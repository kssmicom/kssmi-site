import assert from 'node:assert/strict';
import test from 'node:test';
import { onRequest } from '../src/middleware.ts';

async function request(path) {
  let continued = false;
  const response = await onRequest({request:new Request('https://kssmi.com' + path)}, () => {
    continued = true;
    return new Response('page', {status:200});
  });
  return {response, continued};
}

test('lowercase routes continue without a redirect', async () => {
  const {response, continued} = await request('/product/?q=UPPER');
  assert.equal(response.status, 200);
  assert.equal(continued, true);
});

test('uppercase route letters redirect while retaining the query', async () => {
  const {response, continued} = await request('/Product/?q=UPPER');
  assert.equal(response.status, 301);
  assert.equal(response.headers.get('location'), 'https://kssmi.com/product/?q=UPPER');
  assert.equal(continued, false);
});

test('UTF-8 percent escapes do not create a self redirect', async () => {
  const {response, continued} = await request('/hi/product/fashion-ey%E0%A4%B5ear/');
  assert.equal(response.status, 200);
  assert.equal(continued, true);
});

test('reserved percent escapes keep their representation', async () => {
  const {response, continued} = await request('/product/encoded%2Fname/');
  assert.equal(response.status, 200);
  assert.equal(continued, true);
});

test('literal uppercase letters normalize without rewriting UTF-8 escapes', async () => {
  const {response} = await request('/Product/%E0%A4%B5/?q=UPPER');
  assert.equal(response.headers.get('location'), 'https://kssmi.com/product/%E0%A4%B5/?q=UPPER');
});
