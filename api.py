from flask import Flask, request, Response
import requests

app = Flask(__name__)

@app.route("/cinemana/<id>")
def cinemana(id):
    url = f"https://cinemana.shabakaty.com/api/android/allVideoInfo/id/{id}"

    r = requests.get(
        url,
        headers={
            "User-Agent": "Android 12; Cinemana -1; HUAWEI STK-L21",
            "Accept": "application/json",
            "Accept-Encoding": "gzip",
        },
        timeout=10
    )

    return Response(
        r.content,
        status=r.status_code,
        content_type="application/json"
    )

app.run(host="0.0.0.0", port=5000)
