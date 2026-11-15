import test from 'node:test';
import assert from 'node:assert/strict';
import { partitionSites } from './monitor-site-policy.mjs';

const sites = [['smallnm.com', [500, 200]], ['kssmi.com', 200]];

test('approved expiry parking pauses only the expired host', async () => {
  const queried = [];
  const result = await partitionSites(sites, async (host) => {
    queried.push(host);
    return ['EXPIRED.HICHINA.COM.'];
  });
  assert.deepEqual(queried, ['smallnm.com']);
  assert.deepEqual(result.active, [sites[1]]);
  assert.deepEqual(result.parked, [{ host: 'smallnm.com', target: 'expired.hichina.com' }]);
});

for (const [name, lookup] of [
  ['renewed domain', async () => ['restored.example.com']],
  ['direct A record', async () => { throw Object.assign(new Error('no CNAME'), { code: 'ENODATA' }); }],
  ['DNS outage', async () => { throw Object.assign(new Error('DNS failed'), { code: 'ETIMEOUT' }); }],
  ['unapproved parking', async () => ['expired.hichina.com.attacker.example']],
  ['empty CNAME answer', async () => []],
]) {
  test(`${name} keeps HTTP and TLS checks enabled`, async () => {
    assert.deepEqual(await partitionSites(sites, lookup), { active: sites, parked: [] });
  });
}
