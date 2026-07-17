// Per-language translations — dynamic imports (~11KB per page vs 180KB)
type DeepWiden<T> =
  T extends string ? string :
  T extends number ? number :
  T extends boolean ? boolean :
  T extends readonly (infer U)[] ? readonly DeepWiden<U>[] :
  T extends object ? { [K in keyof T]: DeepWiden<T[K]> } :
  T;

export type Translations = DeepWiden<
  typeof import('./en').default
>;

// ── Optimized per-language loader ──
export const LANGUAGES = ['en','ar','it','es','fr','de','pt','ru','ja','tr','ko','zh','hi','vi','jv','ms','tg'] as const;

// Per-language dynamic import — only the current language is bundled (~11KB vs 180KB)
const langLoaders: Record<string, () => Promise<Translations>> = {
  en: () => import('./en').then(m => m.default),
  ar: () => import('./ar').then(m => m.default),
  it: () => import('./it').then(m => m.default),
  es: () => import('./es').then(m => m.default),
  fr: () => import('./fr').then(m => m.default),
  de: () => import('./de').then(m => m.default),
  pt: () => import('./pt').then(m => m.default),
  ru: () => import('./ru').then(m => m.default),
  ja: () => import('./ja').then(m => m.default),
  tr: () => import('./tr').then(m => m.default),
  ko: () => import('./ko').then(m => m.default),
  zh: () => import('./zh').then(m => m.default),
  hi: () => import('./hi').then(m => m.default),
  vi: () => import('./vi').then(m => m.default),
  jv: () => import('./jv').then(m => m.default),
  ms: () => import('./ms').then(m => m.default),
  tg: () => import('./tg').then(m => m.default),
};

export async function getTranslations(lang: string): Promise<Translations> {
  const loader = langLoaders[lang] || langLoaders.en;
  return loader();
}


export type Lang = typeof LANGUAGES[number];
