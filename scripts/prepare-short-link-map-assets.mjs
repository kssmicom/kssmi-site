import { cp, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const output = resolve(root, 'public/vendor/short-link-map');
await mkdir(output, { recursive: true });
await Promise.all([
  cp(resolve(root, 'node_modules/leaflet/dist/leaflet.js'), resolve(output, 'leaflet.js')),
  cp(resolve(root, 'node_modules/leaflet/dist/leaflet.css'), resolve(output, 'leaflet.css')),
  cp(resolve(root, 'node_modules/topojson-client/dist/topojson-client.min.js'), resolve(output, 'topojson-client.min.js')),
  cp(resolve(root, 'node_modules/world-atlas/countries-110m.json'), resolve(output, 'countries-110m.json')),
]);
