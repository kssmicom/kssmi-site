import path from 'node:path';
import { fixInteractionPolicy } from './interaction-feedback-policy.mjs';

const root = process.cwd();
const changed = await fixInteractionPolicy(root);

if (changed.length === 0) {
  console.log('Cross-device interaction policy already normalized.');
} else {
  console.log(`Cross-device interaction feedback added to ${changed.length} file(s):`);
  for (const filename of changed) {
    console.log(`- ${path.relative(root, filename)}`);
  }
}
