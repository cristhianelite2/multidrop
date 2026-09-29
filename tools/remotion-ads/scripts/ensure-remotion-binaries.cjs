const fs = require('node:fs');
const path = require('node:path');

if (process.platform !== 'linux') {
  process.exit(0);
}

const glibc = process.report?.getReport()?.header?.glibcVersionRuntime;
const libc = glibc ? 'gnu' : 'musl';
const packageName = `@remotion/compositor-linux-${process.arch}-${libc}`;

let compositorDir;
try {
  compositorDir = require(packageName).dir;
} catch (error) {
  console.warn(`No se encontró ${packageName}; se omiten permisos de Remotion.`);
  process.exit(0);
}

for (const binary of ['remotion', 'ffmpeg', 'ffprobe']) {
  const binaryPath = path.join(compositorDir, binary);
  if (fs.existsSync(binaryPath)) {
    fs.chmodSync(binaryPath, 0o755);
  }
}

console.log(`Permisos de ejecución listos para ${packageName}.`);
