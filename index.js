const express = require('express');
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());
const app = express();
const PORT = process.env.PORT || 3000;

app.get('/get-link', async (req, res) => {
    const videoId = req.query.id;
    if (!videoId) return res.json({ success: false, error: "Missing ID" });

    console.log(`[#] Processing ID: ${videoId}`);

    const browser = await puppeteer.launch({
        headless: "new",
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
    });

    try {
        const page = await browser.newPage();
        await page.setUserAgent('Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');

        // الخطوة 1: الدخول لصفحة المشغل
        await page.goto(`https://ghostplayer.store/wp-content/plugins/fmovie-core/player/player.php?video_id=${videoId}`, {
            waitUntil: 'networkidle2',
            referer: 'https://moviespro.watch/'
        });

        // الخطوة 2: انتظار زر تخطي الكابتشا والنقر عليه
        await page.waitForSelector('button[name="button-click"]', { timeout: 15000 });
        await page.click('button[name="button-click"]');

        // الخطوة 3: انتظار تحميل السيرفرات
        await page.waitForResponse(response => response.url().includes('response.php'), { timeout: 15000 });
        await page.waitForSelector('li[data-server]', { timeout: 5000 });

        // الخطوة 4: النقر على السيرفر الأول وجلب الـ iframe
        await page.click('li[data-server]');
        await page.waitForSelector('iframe.source-frame', { timeout: 10000 });

        const finalIframe = await page.evaluate(() => {
            return document.querySelector('iframe.source-frame').src;
        });

        res.json({
            success: true,
            iframe: finalIframe.startsWith('//') ? 'https:' + finalIframe : finalIframe
        });

    } catch (error) {
        res.json({ success: false, error: error.message });
    } finally {
        await browser.close();
    }
});

app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
