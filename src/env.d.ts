/// <reference types="astro/client" />

type DataLayerEntry = Record<string, unknown> | IArguments;

interface ImportMetaEnv {
  readonly PUBLIC_CLOUDFLARE_WEB_ANALYTICS_TOKEN?: string;
  readonly PUBLIC_ENABLE_LOCAL_VJT?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare global {
  interface Window {
    dataLayer: DataLayerEntry[];
  }
}

export {};
