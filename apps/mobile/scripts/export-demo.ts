/**
 * Writes the demo data set to apps/api/database/seeders/demo-data.json.gz so the
 * Laravel seeder loads exactly what the demo app shows.  npm run demo:export
 */
import { writeFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { seedDemo, demoSummary } from '../src/lib/demo';
import { exportAll } from '../src/lib/store';

seedDemo();
const out = new URL('../../api/database/seeders/demo-data.json.gz', import.meta.url);
writeFileSync(out, gzipSync(JSON.stringify(exportAll()), { level: 9 }));
console.log('Wrote', out.pathname, demoSummary());
