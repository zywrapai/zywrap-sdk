
// FILE: download-bundle.js
// USAGE: node download-bundle.js
// REQUIREMENTS: npm install axios adm-zip

const axios = require('axios');
const fs = require('fs');
const AdmZip = require('adm-zip');

// --- CONFIGURATION ---
const ZYWRAP_API_KEY = 'YOUR_API_KEY_HERE';
const API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/v1/download';
const OUTPUT_FILE = './zywrap-data.zip';
// ---------------------

async function downloadBundle() {
  console.log('Downloading latest V1 wrapper data from Zywrap...');
  
  if (!ZYWRAP_API_KEY || ZYWRAP_API_KEY === 'YOUR_API_KEY_HERE') {
      console.error("FATAL: Please replace 'YOUR_API_KEY_HERE' with your actual Zywrap API key.");
      process.exit(1);
  }

  try {
    const response = await axios.get(API_ENDPOINT, {
      headers: { 'Authorization': 'Bearer ' + ZYWRAP_API_KEY },
      responseType: 'stream'
    });

    const writer = fs.createWriteStream(OUTPUT_FILE);
    response.data.pipe(writer);

    return new Promise((resolve, reject) => {
      writer.on('finish', () => {
        console.log("✅ Data bundle downloaded successfully.");
        
        try {
            console.log('📦 Extracting files...');
            const zip = new AdmZip(OUTPUT_FILE);
            zip.extractAllTo(__dirname, true);
            console.log('✅ Extraction complete.');
            
            // Clean up the zip file
            fs.unlinkSync(OUTPUT_FILE);
            
            console.log("👉 Next Step: Run 'node import.js' to load the data into your database.");
            resolve();
        } catch (zipErr) {
            console.error("⚠️ Failed to extract the zip file automatically.");
            console.log("Please manually unzip '" + OUTPUT_FILE + "' and run 'node import.js'.");
            resolve();
        }
      });
      writer.on('error', reject);
      response.data.on('error', reject);
    });

  } catch (error) {
    if (fs.existsSync(OUTPUT_FILE)) fs.unlinkSync(OUTPUT_FILE);
    console.error("❌ Error downloading bundle: " + (error.response?.status || error.message));
  }
}

downloadBundle();
