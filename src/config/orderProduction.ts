/**
 * Company-wide ordering and production facts.
 *
 * Keep the numeric terms here so product pages and future FAQ/AI content use
 * the same source of truth. Durations are working days.
 */
export const orderProduction = Object.freeze({
  logoFeePerModel: 15,
  readyStockMinDays: 2,
  readyStockMaxDays: 5,
  readyStockWithLogoDays: 10,
  renderingMinDays: 5,
  renderingMaxDays: 10,
  customSampleMinDays: 45,
  customSampleMaxDays: 60,
  bulkProductionMinDays: 50,
  bulkProductionMaxDays: 60,
  renderingRevisionRounds: 3,
  standardMoq: 300,
  colorsPerModel: 3,
  minimumPerColor: 100,
  tr90Moq: 1200,
  bulkDepositPercent: 50,
  sampleAdvancePercent: 100,
});

export const formatUsd = (amount: number) => `US$${amount}`;
export const formatDayRange = (min: number, max: number) => `${min}–${max}`;
