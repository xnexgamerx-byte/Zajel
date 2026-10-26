"""إعداد Firebase لبناء التطبيق (docs/plan/82).

يقرأ google-services.json من سرّ المستودع GOOGLE_SERVICES_JSON_B64 (base64)، ويأخذ منه إعداد التطبيق
بمعرّفه (iq.wahaj.merchant_app…)، ويكتبه مع ملف إعداد الشركة في ملفٍّ واحدٍ لـ--dart-define-from-file.
بلا السرّ، أو بسرٍّ ليس فيه التطبيق، يُبنى التطبيق بلا إشعارات الهاتف — لا يفشل البناء — وتظهر ملاحظة.
لا يُطبع من السرّ شيء.

    python3 firebase_defines.py <package> <config.json> <out.json>
"""

import base64
import json
import os
import sys

package, config_path, out_path = sys.argv[1:4]

with open(config_path, encoding="utf-8") as f:
    defines = json.load(f)

raw = os.environ.get("GOOGLE_SERVICES_JSON_B64", "").strip()
client = None
project = {}

if raw:
    try:
        services = json.loads(base64.b64decode(raw))
        project = services.get("project_info", {})
        client = next(
            (c for c in services.get("client", [])
             if c.get("client_info", {}).get("android_client_info", {}).get("package_name") == package),
            None,
        )
    except (ValueError, json.JSONDecodeError):
        print("::warning::GOOGLE_SERVICES_JSON_B64 ليس base64 لملف google-services.json — يُبنى بلا إشعارات الهاتف.")

if client and client.get("api_key") and project.get("project_number") and project.get("project_id"):
    defines.update({
        "FIREBASE_API_KEY": client["api_key"][0]["current_key"],
        "FIREBASE_APP_ID": client["client_info"]["mobilesdk_app_id"],
        "FIREBASE_SENDER_ID": project["project_number"],
        "FIREBASE_PROJECT_ID": project["project_id"],
    })
    print(f"Firebase: {package} in {project['project_id']}")
elif raw:
    print(f"::warning::{package} غير مسجّل في google-services.json الذي في السرّ — يُبنى بلا إشعارات الهاتف.")
else:
    print("::warning::لا سرّ GOOGLE_SERVICES_JSON_B64 — يُبنى بلا إشعارات الهاتف.")

with open(out_path, "w", encoding="utf-8") as f:
    json.dump(defines, f, ensure_ascii=False)
