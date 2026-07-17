import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => readFileSync(path.join(root, relativePath), 'utf8');
const failures = [];

function assert(condition, message) {
  if (!condition) failures.push(message);
}

function unwrapExpression(expression) {
  let current = expression;

  while (
    current
    && (
      ts.isAsExpression(current)
      || ts.isParenthesizedExpression(current)
      || ts.isSatisfiesExpression(current)
      || ts.isTypeAssertionExpression(current)
    )
  ) {
    current = current.expression;
  }

  return current;
}

function getVariable(sourceFile, name) {
  for (const statement of sourceFile.statements) {
    if (!ts.isVariableStatement(statement)) continue;

    for (const declaration of statement.declarationList.declarations) {
      if (ts.isIdentifier(declaration.name) && declaration.name.text === name) {
        return { declaration, statement };
      }
    }
  }

  return null;
}

function getPropertyName(property) {
  const name = property.name;
  if (!name) return null;
  if (ts.isIdentifier(name) || ts.isStringLiteral(name) || ts.isNumericLiteral(name)) return name.text;
  return null;
}

function hasRuntimeBinding(importDeclaration) {
  const clause = importDeclaration.importClause;
  if (!clause) return true;
  if (clause.isTypeOnly) return false;
  if (clause.name) return true;

  const bindings = clause.namedBindings;
  if (!bindings) return false;
  if (ts.isNamespaceImport(bindings)) return true;
  return bindings.elements.some((element) => !element.isTypeOnly);
}

const indexPath = 'src/translations/index.ts';
const paginationPath = 'src/components/Pagination.astro';
const indexSource = read(indexPath);
const pagination = read(paginationPath);
const sourceFile = ts.createSourceFile(indexPath, indexSource, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
const paginationCallers = [
  'src/components/pages/ProductListingPage.astro',
  'src/components/pages/ProductCategoryPage.astro',
];

const translationsVariable = getVariable(sourceFile, 'translations');
const translationsIsExported = translationsVariable?.statement.modifiers?.some(
  (modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword,
) ?? false;
assert(!translationsIsExported, `${indexPath} must not export a runtime translations compatibility table.`);

assert(
  /await\s+getTranslations\(\s*lang\s*\)/.test(pagination),
  `${paginationPath} must load labels with await getTranslations(lang).`,
);
assert(!/\btranslations\s*\[/.test(pagination), `${paginationPath} must not read a translations compatibility table.`);
assert(
  !/getTranslations\(\s*['"]en['"]\s*\)/.test(pagination),
  `${paginationPath} must not hard-code English as the pagination language.`,
);

for (const callerPath of paginationCallers) {
  const caller = read(callerPath);
  const paginationTags = caller.match(/<Pagination\b[\s\S]*?\/>/g) ?? [];

  assert(paginationTags.length > 0, `${callerPath} must render the shared Pagination component.`);
  paginationTags.forEach((tag, index) => {
    const location = paginationTags.length > 1 ? ` call ${index + 1}` : '';
    assert(/\blang=\{lang\}/.test(tag), `${callerPath}${location} must pass lang={lang} to Pagination.`);
    assert(
      /\bdir=\{isRTL\s*\?\s*['"]rtl['"]\s*:\s*['"]ltr['"]\}/.test(tag),
      `${callerPath}${location} must pass the current text direction to Pagination.`,
    );
  });
}

const runtimeEnglishImports = sourceFile.statements.filter(
  (statement) => ts.isImportDeclaration(statement)
    && ts.isStringLiteral(statement.moduleSpecifier)
    && statement.moduleSpecifier.text === './en'
    && hasRuntimeBinding(statement),
);
assert(
  runtimeEnglishImports.length === 0,
  `${indexPath} must not statically import the English translation at runtime.`,
);

const languagesVariable = getVariable(sourceFile, 'LANGUAGES');
const languagesInitializer = unwrapExpression(languagesVariable?.declaration.initializer);
const languages = languagesInitializer && ts.isArrayLiteralExpression(languagesInitializer)
  ? languagesInitializer.elements
    .filter((element) => ts.isStringLiteral(element))
    .map((element) => element.text)
  : [];
assert(languages.length > 0, `${indexPath} must declare LANGUAGES as a string array.`);
assert(new Set(languages).size === languages.length, `${indexPath} LANGUAGES must not contain duplicates.`);

const loadersVariable = getVariable(sourceFile, 'langLoaders');
const loadersInitializer = unwrapExpression(loadersVariable?.declaration.initializer);
const loaderNames = loadersInitializer && ts.isObjectLiteralExpression(loadersInitializer)
  ? loadersInitializer.properties.map(getPropertyName).filter(Boolean)
  : [];
assert(loaderNames.length > 0, `${indexPath} must declare langLoaders as an object.`);

const loaderSet = new Set(loaderNames);
const missingLoaders = languages.filter((language) => !loaderSet.has(language));
assert(
  missingLoaders.length === 0,
  `${indexPath} is missing loaders for: ${missingLoaders.join(', ')}.`,
);

if (failures.length) {
  console.error('Translation regression validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log(`Translation regression validation passed for ${languages.length} languages.`);
console.log('- No runtime translations compatibility table is exported.');
console.log('- Pagination loads labels for the requested language.');
console.log('- Shared pagination callers forward language and text direction.');
console.log('- English remains a type-only/dynamic dependency.');
console.log('- Every declared language has a dynamic loader.');
