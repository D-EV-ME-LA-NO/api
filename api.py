from js import Response, fetch
import time
from urllib.parse import quote

async def on_fetch(request):
    # 1. استخراج كلمة البحث من الرابط (q)
    url_str = request.url
    try:
        # تقسيم الرابط للحصول على قيمة q
        q = url_str.split('q=')[1].split('&')[0]
    except:
        q = "anaconda" # قيمة افتراضية إذا لم يوجد بحث

    t = int(time.time())
    
    # 2. بناء رابط البحث الفعلي
    api_url = f"https://net52.cc/mobile/search.php?s={quote(q)}&t={t}"

    # 3. إعداد الرؤوس (Headers) والكوكيز
    # ملاحظة: تأكد أن الـ t_hash لا تزال صالحة
    headers = {
        "User-Agent": "Mozilla/5.0 (Linux; Android 10; STK-L21 Build/HUAWEISTK-L21; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/145.0.7632.159 Mobile Safari/537.36 /OS.Gatu v3.0",
        "X-Requested-With": "XMLHttpRequest",
        "Referer": "https://net52.cc/mobile/home?app=1",
        "Cookie": "t_hash_t=c394297c8c8172daa8e063f4adf3b1ec%3A%3A0c9cacded901a7fa453e3b6b1b6087aa%3A%3A1774065594%3A%3Aqi; t_hash=8fbdc057f9b84a8402416559af5b54ec%3A%3A1774067356%3A%3Aqi; recentplay=786786; ott=nf;"
    }

    # 4. تنفيذ الطلب باستخدام fetch المتوافقة مع كلاود فلير
    try:
        res = await fetch(api_url, method="GET", headers=headers)
        data = await res.text()
        
        # إرجاع النتيجة كـ JSON مع السماح بـ CORS ليعمل على أي موقع
        return Response.new(data, headers={
            "Content-Type": "application/json; charset=utf-8",
            "Access-Control-Allow-Origin": "*"
        })
    except Exception as e:
        return Response.new(f'{{"error": "{str(e)}"}}', status=500)
