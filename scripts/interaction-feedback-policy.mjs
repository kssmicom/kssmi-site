import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const SOURCE_ROOTS = Object.freeze([
  {
    directory: 'src',
    extensions: new Set(['.astro', '.css', '.js', '.mjs', '.ts', '.tsx']),
  },
  {
    directory: 'public',
    extensions: new Set(['.css', '.html', '.js', '.php']),
  },
]);

const IGNORED_DIRECTORIES = new Set(['node_modules', 'pagefind', 'vendor']);

// The utility payload cannot start with `:`. This keeps CSS selectors such as
// `.card:hover::before` out of the Tailwind-token path.
const HOVER_UTILITY_PATTERN = /(?<![A-Za-z0-9_-])((?:(?:[A-Za-z0-9_-]+):)*(?:(?:group|peer)-hover(?:\/[A-Za-z0-9_-]+)?|hover):(?!:)[^\s"'`<>]+)/g;
const HOVER_MARKER_PATTERN = /(^|:)((?:group|peer)-)?hover(\/[A-Za-z0-9_-]+)?:/;
const INTERACTION_UTILITY_PATTERN = /(?<![A-Za-z0-9_-])((?:(?:[A-Za-z0-9_-]+):)*(?:(?:group|peer)-(?:hover|active|focus-visible|focus-within)(?:\/[A-Za-z0-9_-]+)?|hover|active|focus-visible|focus-within):(?!:)[^\s"'`<>]+)/g;
const INTERACTION_MARKER_PATTERN = /(^|:)((?:group|peer)-)?(hover|active|focus-visible|focus-within)(\/[A-Za-z0-9_-]+)?:(.+)$/;

function utilityProperty(payload) {
  const value = payload.replace(/^-/, '');
  if (/^shadow(?:-(?:sm|md|lg|xl|2xl|inner|none))?$/.test(value)) return 'shadow-size';
  if (value.startsWith('shadow-')) return 'shadow-color';
  if (/^ring(?:-(?:0|1|2|4|8|inset))?$/.test(value)) return 'ring-size';
  if (value.startsWith('ring-offset-')) return 'ring-offset';
  if (value.startsWith('ring-')) return 'ring-color';
  if (value.startsWith('translate-x-')) return 'translate-x';
  if (value.startsWith('translate-y-')) return 'translate-y';
  if (value.startsWith('scale-')) return 'scale';
  if (value.startsWith('border-')) return 'border';
  if (value.startsWith('from-')) return 'gradient-from';
  if (value.startsWith('via-')) return 'gradient-via';
  if (value.startsWith('to-')) return 'gradient-to';
  return value.match(/^[A-Za-z0-9]+(?:-[xytrblse])?/)?.[0] || value;
}

function interactionSignature(utility) {
  const marker = utility.match(INTERACTION_MARKER_PATTERN);
  if (!marker) return null;
  return {
    prefix: utility.slice(0, marker.index + marker[1].length),
    family: marker[2] || '',
    state: marker[3],
    name: marker[4] || '',
    property: utilityProperty(marker[5]),
  };
}

function lineHasEquivalentState(line, equivalent) {
  const expected = interactionSignature(equivalent);
  if (!expected) return line.includes(equivalent);

  for (const match of line.matchAll(INTERACTION_UTILITY_PATTERN)) {
    const candidate = interactionSignature(match[1]);
    if (
      candidate
      && candidate.prefix === expected.prefix
      && candidate.family === expected.family
      && candidate.state === expected.state
      && candidate.name === expected.name
      && candidate.property === expected.property
    ) {
      return true;
    }
  }

  return false;
}

function equivalentVariants(hoverUtility) {
  const marker = hoverUtility.match(HOVER_MARKER_PATTERN);
  if (!marker) return [];

  const family = marker[2] || '';
  const name = marker[3] || '';
  const states = family === 'group-'
    ? ['group-active', 'group-focus-visible', 'group-focus-within']
    : family === 'peer-'
      ? ['peer-active', 'peer-focus-visible']
      : ['active', 'focus-visible', 'focus-within'];

  return states.map((state) => hoverUtility.replace(
    HOVER_MARKER_PATTERN,
    `${marker[1]}${state}${name}:`,
  ));
}

function expandUtilityLine(line) {
  const originalLine = line;
  const planned = new Set();

  return line.replace(HOVER_UTILITY_PATTERN, (hoverUtility) => {
    const equivalents = equivalentVariants(hoverUtility).filter((equivalent) => {
      if (lineHasEquivalentState(originalLine, equivalent) || planned.has(equivalent)) return false;
      planned.add(equivalent);
      return true;
    });

    return equivalents.length > 0
      ? `${hoverUtility} ${equivalents.join(' ')}`
      : hoverUtility;
  });
}

function expandCssHoverLine(line) {
  // Match CSS pseudo-classes (`:hover`, `:hover::before`) without matching
  // Tailwind variant chains (`motion-safe:hover:-translate-y-1`).
  const expandedLine = line.replaceAll(
    ':is(:hover, :active, :focus-visible)',
    ':is(:hover, :active, :focus-visible, :focus-within)',
  );
  return expandedLine.replace(/:hover\b(?=$|[^:]|::)/g, (hover, offset) => (
    expandedLine.slice(Math.max(0, offset - 4), offset) === ':is('
      ? hover
      : ':is(:hover, :active, :focus-visible, :focus-within)'
  ));
}

export function expandInteractionFeedback(source) {
  const newline = source.includes('\r\n') ? '\r\n' : '\n';
  return source
    .split(/\r?\n/)
    .map((line) => expandCssHoverLine(expandUtilityLine(line)))
    .join(newline);
}

async function collectFiles(directory, extensions, files = []) {
  let entries;
  try {
    entries = await readdir(directory, { withFileTypes: true });
  } catch (error) {
    if (error?.code === 'ENOENT') return files;
    throw error;
  }

  for (const entry of entries) {
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (!IGNORED_DIRECTORIES.has(entry.name)) {
        await collectFiles(absolutePath, extensions, files);
      }
      continue;
    }

    if (entry.isFile() && extensions.has(path.extname(entry.name))) {
      files.push(absolutePath);
    }
  }

  return files;
}

export async function interactionSourceFiles(root = process.cwd()) {
  const groups = await Promise.all(SOURCE_ROOTS.map(({ directory, extensions }) => (
    collectFiles(path.join(root, directory), extensions)
  )));
  return groups.flat().sort((left, right) => left.localeCompare(right));
}

export async function findInteractionPolicyViolations(root = process.cwd()) {
  const violations = [];

  for (const filename of await interactionSourceFiles(root)) {
    const source = await readFile(filename, 'utf8');
    const expanded = expandInteractionFeedback(source);
    if (expanded === source) continue;

    const sourceLines = source.split(/\r?\n/);
    const expandedLines = expanded.split(/\r?\n/);
    const lines = [];
    for (let index = 0; index < sourceLines.length; index += 1) {
      if (sourceLines[index] !== expandedLines[index]) lines.push(index + 1);
    }

    violations.push({ filename, lines });
  }

  return violations;
}

export async function fixInteractionPolicy(root = process.cwd()) {
  const changed = [];

  for (const filename of await interactionSourceFiles(root)) {
    const source = await readFile(filename, 'utf8');
    const expanded = expandInteractionFeedback(source);
    if (expanded === source) continue;

    await writeFile(filename, expanded, 'utf8');
    changed.push(filename);
  }

  return changed;
}
