// FILE: download-bundle.js
const axios = require('axios');
const fs = require('fs');

const ZYWRAP_API_KEY = 'YOUR_API_KEY';
const API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/export/';
const OUTPUT_FILE = './zywrap-data.zip';

async function downloadBundle() {
  console.log('Downloading latest wrapper data from Zywrap...');
  
  try {
    const response = await axios.get(API_ENDPOINT, {
      headers: { 'Authorization': `Bearer ${ZYWRAP_API_KEY}` },
      responseType: 'stream'
    });

    const writer = fs.createWriteStream(OUTPUT_FILE);
    response.data.pipe(writer);

    return new Promise((resolve, reject) => {
      writer.on('finish', () => {
        console.log(`✅ Sync complete. Data saved to ${OUTPUT_FILE}.`);
        console.log("Run 'unzip zywrap-data.zip' to extract the 'zywrap-data.json' file.");
        resolve();
      });
      writer.on('error', reject);
      response.data.on('error', reject);
    });

  } catch (error) {
    console.error(`Error downloading bundle: ${error.response?.status || error.message}`);
    if (error.response) {
      console.error(error.response.data.toString());
    }
  }
}

downloadBundle();
