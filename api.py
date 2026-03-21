import requests

cookies = {
    't_hash_t': 'c394297c8c8172daa8e063f4adf3b1ec%3A%3A0c9cacded901a7fa453e3b6b1b6087aa%3A%3A1774065594%3A%3Aqi',
    '_ga': 'GA1.1.1759485993.1774065595',
    '_clck': 'adlkph%5E2%5Eg4j%5E0%5E2271',
    'recentplay': '786786',
    'HstCfa1188575': '1774066364194',
    'HstCmu1188575': '1774066364194',
    'HstCnv1188575': '1',
    'HstCns1188575': '1',
    '__dtsu': '4C301774066365F28283170A885C0EC2',
    '_pubcid': 'bf91bbe7-8cce-4a8c-8f8e-d207e2c6df4a',
    '_cc_id': '1bfa1d7ea7aef9008eb75b118000eca2',
    '_cc_cc': 'ACZ4nGNQMExKSzRMMU9NNE9MTbM0MLBITTI3TTI0tDAwMEhNTjRiAILMfVIHGBAAAG9cC1w%3D',
    '_cc_aud': 'ABR4nGNgYGDI3Cd1gAEOABjuAgI%3D',
    'panoramaId_expiry': '1774152768196',
    'SE_0JGL8Y3ISW13ENHIFNVJFSEWCG': '0Q9TEXARMYXUPXUWH63JEZ5B4L',
    'pv_recentplay': 'SE_0JGL8Y3ISW13ENHIFNVJFSEWCG',
    'HstCla1188575': '1774066524404',
    'HstPn1188575': '13',
    'HstPt1188575': '13',
    'ott': 'nf',
    't_hash': '8fbdc057f9b84a8402416559af5b54ec%3A%3A1774067356%3A%3Aqi',
    '_ga_6H28ST6E9Y': 'GS2.1.s1774069782$o2$g0$t1774069782$j60$l0$h0',
    '_clsk': 'xcc07w%5E1774069784649%5E1%5E0%5El.clarity.ms%2Fcollect',
}

headers = {
    'User-Agent': 'Mozilla/5.0 (Linux; Android 10; STK-L21 Build/HUAWEISTK-L21; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/145.0.7632.159 Mobile Safari/537.36 /OS.Gatu v3.0',
    # 'Accept-Encoding': 'gzip, deflate, br, zstd',
    'sec-ch-ua-platform': '"Android"',
    'x-requested-with': 'XMLHttpRequest',
    'sec-ch-ua': '"Not:A-Brand";v="99", "Android WebView";v="145", "Chromium";v="145"',
    'sec-ch-ua-mobile': '?1',
    'sec-fetch-site': 'same-origin',
    'sec-fetch-mode': 'cors',
    'sec-fetch-dest': 'empty',
    'referer': 'https://net52.cc/mobile/home?app=1',
    'accept-language': 'ar-IQ,ar;q=0.9,en-IQ;q=0.8,en-US;q=0.7,en;q=0.6',
    'priority': 'u=1, i',
    # 'Cookie': 't_hash_t=c394297c8c8172daa8e063f4adf3b1ec%3A%3A0c9cacded901a7fa453e3b6b1b6087aa%3A%3A1774065594%3A%3Aqi; _ga=GA1.1.1759485993.1774065595; _clck=adlkph%5E2%5Eg4j%5E0%5E2271; recentplay=786786; HstCfa1188575=1774066364194; HstCmu1188575=1774066364194; HstCnv1188575=1; HstCns1188575=1; __dtsu=4C301774066365F28283170A885C0EC2; _pubcid=bf91bbe7-8cce-4a8c-8f8e-d207e2c6df4a; _cc_id=1bfa1d7ea7aef9008eb75b118000eca2; _cc_cc=ACZ4nGNQMExKSzRMMU9NNE9MTbM0MLBITTI3TTI0tDAwMEhNTjRiAILMfVIHGBAAAG9cC1w%3D; _cc_aud=ABR4nGNgYGDI3Cd1gAEOABjuAgI%3D; panoramaId_expiry=1774152768196; SE_0JGL8Y3ISW13ENHIFNVJFSEWCG=0Q9TEXARMYXUPXUWH63JEZ5B4L; pv_recentplay=SE_0JGL8Y3ISW13ENHIFNVJFSEWCG; HstCla1188575=1774066524404; HstPn1188575=13; HstPt1188575=13; ott=nf; t_hash=8fbdc057f9b84a8402416559af5b54ec%3A%3A1774067356%3A%3Aqi; _ga_6H28ST6E9Y=GS2.1.s1774069782$o2$g0$t1774069782$j60$l0$h0; _clsk=xcc07w%5E1774069784649%5E1%5E0%5El.clarity.ms%2Fcollect',
}

params = {
    's': 'anaconda',
    't': '1774069780',
}

response = requests.get('https://net52.cc/mobile/search.php', params=params, cookies=cookies, headers=headers)
print(response.text)
