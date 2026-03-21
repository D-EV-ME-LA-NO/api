from js import Response, fetch, Headers # أضف Headers هنا
import time
from urllib.parse import quote

async def on_fetch(request):
    url_str = request.url
    try:
        q = url_str.split('q=')[1].split('&')[0]
    except:
        q = "anaconda"

    t = int(time.time())
    api_url = f"https://net52.cc/mobile/search.php?s={quote(q)}&t={t}"

    # 1. إنشاء كائن Headers رسمي من مكتبة js
    my_headers = Headers.new()
    my_headers.append("User-Agent", "Mozilla/5.0 (Linux; Android 10; STK-L21 Build/HUAWEISTK-L21; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/145.0.7632.159 Mobile Safari/537.36 /OS.Gatu v3.0")
    my_headers.append("X-Requested-With", "XMLHttpRequest")
    my_headers.append("Referer", "https://net52.cc/mobile/home?app=1")
    my_headers.append("Cookie", "t_hash_t=c394297c8c8172daa8e063f4adf3b1ec%3A%3A0c9cacded901a7fa453e3b6b1b6087aa%3A%3A1774065594%3A%3Aqi; t_hash=8fbdc057f9b84a8402416559af5b54ec%3A%3A1774067356%3A%3Aqi; recentplay=786786; ott=nf;")

    try:
        # 2. تمرير كائن Headers الموثق
        res = await fetch(api_url, method="GET", headers=my_headers)
        data = await res.text()
        
        return Response.new(data, headers=Headers.new({"Content-Type": "application/json; charset=utf-8"}))
    except Exception as e:
        return Response.new(f'{{"error": "{str(e)}"}}', status=500)
