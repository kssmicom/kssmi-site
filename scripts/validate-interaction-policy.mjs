import path from 'node:path';
import { findInteractionPolicyViolations } from './interaction-feedback-policy.mjs';

const root = process.cwd();
const violations = await findInteractionPolicyViolations(root);

if (violations.length > 0) {
  console.error('Cross-device interaction policy validation failed.');
  console.error('Every hover state must provide touch active and keyboard focus feedback.');
  for (const { filename, lines } of violations) {
    console.error(`- ${path.relative(root, filename)}:${lines.join(',')}`);
  }
  console.error('Run "npm run fix:interactions" and review the resulting changes.');
  process.exit(1);
}

console.log('Cross-device interaction policy validation passed.');
