import { readFile } from 'node:fs/promises';

const evidence = JSON.parse(await readFile(process.argv[2] ?? 'release-evidence/final/release-evidence.json', 'utf8'));
const accepted = evidence?.evidence_type === 'kssmi_environment_deployment'
  ? evidence.status === 'PASS'
  : evidence?.signoff?.status === 'APPROVED';
if (!accepted) {
  console.error('Release signoff is BLOCKED. Download the evidence artifact for the failed requirements.');
  process.exit(1);
}
console.log(`Evidence gate accepted: ${evidence.release_id}`);
