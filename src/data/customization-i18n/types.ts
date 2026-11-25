export interface CustomizationGroupCopy {
  seoTitle: string;
  seoDescription: string;
  heading: string;
  intro: string;
  quickPills: string[];
  guideTitle: string;
  guideSubtitle: string;
  tableHeaders: string[];
  tableRows: string[][];
  engineeringTips: string[];
  faqTitle: string;
  faqs: Array<{ question: string; answer: string }>;
}

export interface CustomizationUiCopy {
  breadcrumbLabel: string;
  allCustomization: string;
  allShort: string;
  categoriesAria: string;
  subcategoriesAria: string;
  showingCounter: string;
  zoomHint: string;
  selectForQuote: string;
  selectedRemove: string;
  inspectAria: string;
  altTemplate: string;
  fallbackGroupAlt: string;
  engineeringGuideEyebrow: string;
  factoryNotesHeading: string;
  technicalQaEyebrow: string;
  trayAria: string;
  trayHeading: string;
  copyCodes: string;
  copied: string;
  requestQuote: string;
  clearAllTitle: string;
  clearAllAria: string;
  removeCodeTitle: string;
  removeCodeAria: string;
  lightboxAria: string;
  closePreviewAria: string;
  closeLabel: string;
  magnifierStageAria: string;
  magnifierHint: string;
  quotePanelLabel: string;
  quoteClearSelection: string;
  quotePanelHelper: string;
}

export interface CustomizationLocaleBundle {
  lang: string;
  ui: CustomizationUiCopy;
  groups: Record<string, { name: string; shortName: string }>;
  subcategories: Record<string, string>;
  badges: Record<string, string>;
  blueBlockPrefix: string;
  copy: Record<string, CustomizationGroupCopy>;
  titles: Record<string, string>;
  notes: Record<string, string>;
}
