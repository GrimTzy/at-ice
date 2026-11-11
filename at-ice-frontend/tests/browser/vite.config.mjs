import { defineConfig, mergeConfig } from 'vite';
import path from 'node:path';
import original from '../../vite.config.js';

export default defineConfig(mergeConfig(original({ command: 'serve' }), {
  envDir: path.join(process.env.ATICE_TEST_ROOT, 'env'),
  cacheDir: path.join(process.env.ATICE_TEST_ROOT, 'vite'),
  define: { 'import.meta.env.VITE_PUSHER_APP_KEY': '""' },
  server: { host: '127.0.0.1', port: 15173, strictPort: true, proxy: { '/api': 'http://127.0.0.1:18080' } },
}));
