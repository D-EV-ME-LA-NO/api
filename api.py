from js import Response, fetch, Headers
import time

async def on_fetch(request):
    try:
        # 1. استخراج الكلمة
        url_str = request.url
        q = "anaconda"
        if "q=" in url_str:
            q = url_str.split("q=")[1].split("&")[0]

        t = int(time.time())
        api_url = f"https://net52.cc/mobile/search.php?s={q}&t={t}"

        # 2. بناء الرؤوس كقائمة من الأزواج (Sequence of Pairs)
        # هذا التنسيق هو ما يطلبه الخطأ 
        headers_list = [
            ("User-Agent", "Mozilla/5.0 (Linux; Android 10; STK-L21 Build/HUAWEISTK-L21; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/145.0.7632.159 Mobile Safari/537.36 /OS.Gatu v3.0"),
            ("X-Requested-With", "XMLHttpRequest"),
            ("Referer", "https://net52.cc/mobile/home?app=1"),
            ("Cookie", "t_hash_t=c394297c8c8172daa8e063f4adf3b1ec%3A%3A0c9cacded901a7fa453e3b6b1b6087aa%3A%3A1774065594%3A%3Aqi; t_hash=8fbdc057f9b84a8402416559af5b54ec%3A%3A1774067356%3A%3Aqi; recentplay=786786; ott=nf;")
        ]

        # 3. إرسال الطلب مع تمرير القائمة مباشرة
        res = await fetch(api_url, method="GET", headers=headers_list)
        
        data = await res.text()

        # 4. إرجاع النتيجة مع رؤوس استجابة بسيطة
        response_headers = [
            ("Content-Type", "application/json; charset=utf-8"),
            ("Access-Control-Allow-Origin", "*")
        ]
        
        return Response.new(data, headers=response_headers)

    except Exception as err:
        return Response.new(f'{{"error": "{str(err)}"}}', status=500)
