const fs = require('fs');

module.exports = async function globalTeardown() {
  const dataDir = process.env.KIDS_POINT_E2E_DATA_DIR;
  if (dataDir) {
    fs.rmSync(dataDir, { recursive: true, force: true });
  }
};
