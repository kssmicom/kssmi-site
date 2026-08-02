/**
 * Company-wide sample pricing.
 *
 * Keep numeric values here so the visible product-page pricing and Product
 * structured data always use the same source of truth.
 */
export const samplePricing = Object.freeze({
  currency: 'USD',
  readyStock: 60,
  customMin: 300,
  customMax: 600,
  additionalSameDesign: 60,
});

export const formatSamplePrice = (amount: number) => `US$${amount}`;
