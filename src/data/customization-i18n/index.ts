import type { CustomizationGroup, CustomizationItem } from '../customizationCatalog';
import type { CustomizationLocaleBundle } from './types';

export type { CustomizationGroupCopy, CustomizationLocaleBundle, CustomizationUiCopy } from './types';

export const CUSTOMIZATION_LANGUAGES = [
  'en',
  'it',
  'es',
  'fr',
  'de',
  'pt',
  'ru',
  'ja',
  'tr',
  'ar',
  'ko',
  'zh',
  'hi',
  'vi',
  'jv',
  'ms',
  'tg',
] as const;

const localeLoaders: Record<string, () => Promise<CustomizationLocaleBundle>> = {
  en: () => import('./en').then((m) => m.default),
  it: () => import('./it').then((m) => m.default),
  es: () => import('./es').then((m) => m.default),
  fr: () => import('./fr').then((m) => m.default),
  de: () => import('./de').then((m) => m.default),
  pt: () => import('./pt').then((m) => m.default),
  ru: () => import('./ru').then((m) => m.default),
  ja: () => import('./ja').then((m) => m.default),
  tr: () => import('./tr').then((m) => m.default),
  ar: () => import('./ar').then((m) => m.default),
  ko: () => import('./ko').then((m) => m.default),
  zh: () => import('./zh').then((m) => m.default),
  hi: () => import('./hi').then((m) => m.default),
  vi: () => import('./vi').then((m) => m.default),
  jv: () => import('./jv').then((m) => m.default),
  ms: () => import('./ms').then((m) => m.default),
  tg: () => import('./tg').then((m) => m.default),
};

export async function getCustomizationLocale(lang: string): Promise<CustomizationLocaleBundle> {
  const loader = localeLoaders[lang] || localeLoaders.en;
  return loader();
}

export function formatCustomTemplate(
  template: string,
  vars: Record<string, string | number>,
): string {
  return template.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, key) =>
    Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : `{${key}}`,
  );
}

export function localizeBadge(badge: string, bundle: CustomizationLocaleBundle): string {
  if (Object.prototype.hasOwnProperty.call(bundle.badges, badge)) {
    return bundle.badges[badge];
  }
  if (badge.startsWith('Blue Block ')) {
    return `${bundle.blueBlockPrefix} ${badge.slice('Blue Block '.length)}`;
  }
  return badge;
}

export function localizeCustomizationItem(
  item: CustomizationItem,
  bundle: CustomizationLocaleBundle,
): CustomizationItem {
  return {
    ...item,
    title: Object.prototype.hasOwnProperty.call(bundle.titles, item.title)
      ? bundle.titles[item.title]
      : item.title,
    note: Object.prototype.hasOwnProperty.call(bundle.notes, item.note)
      ? bundle.notes[item.note]
      : item.note,
    badges: item.badges.map((b) => localizeBadge(b, bundle)),
  };
}

export function localizeCustomizationGroups(
  groups: CustomizationGroup[],
  bundle: CustomizationLocaleBundle,
): CustomizationGroup[] {
  return groups.map((group) => {
    const localizedGroup = bundle.groups[group.slug];
    return {
      ...group,
      name: localizedGroup?.name || group.name,
      shortName: localizedGroup?.shortName || group.shortName,
      subcategories: group.subcategories.map((sub) => {
        const subKey = `${group.slug}/${sub.slug}`;
        return {
          ...sub,
          name: bundle.subcategories[subKey] || sub.name,
          items: sub.items.map((item) => localizeCustomizationItem(item, bundle)),
        };
      }),
    };
  });
}

export function buildLocalizedCatalogSpecMap(
  groups: CustomizationGroup[],
  bundle: CustomizationLocaleBundle,
): Record<string, string> {
  const map: Record<string, string> = Object.create(null);
  for (const group of groups) {
    for (const sub of group.subcategories) {
      for (const item of sub.items) {
        const code = item.code.trim().toUpperCase();
        const title = (bundle.titles[item.title] || item.title)
          .replace(/[\u0000-\u001f\u007f]/g, '')
          .trim();
        map[code] = title || code;
      }
    }
  }
  return map;
}
