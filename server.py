import os
import secrets
import time
from datetime import datetime
from flask import Flask, request, jsonify, send_from_directory, render_template_string

# --- CONFIGURATION ---
API_KEY = "change-me-to-something-secure"
UPLOAD_DIR = "uploads"
PORT = 5000
HOST = "0.0.0.0"  # Allows access via IP:PORT

# Ensure upload directory exists
if not os.path.exists(UPLOAD_DIR):
    os.makedirs(UPLOAD_DIR)

app = Flask(__name__)

# --- AUTHENTICATION HELPERS ---
def authenticate():
    # Check header, form, or query param
    key = request.headers.get("X-API-Key") or request.form.get("api_key") or request.args.get("api_key")
    return key == API_KEY

# --- API ENDPOINTS ---

@app.route("/", methods=["GET", "POST"])
def manage_files():
    # 1. API: List Files (GET ?action=list)
    if request.method == "GET" and request.args.get("action") == "list":
        if not authenticate():
            return jsonify({"status": "error", "message": "Invalid API Key"}), 403
            
        files = []
        for filename in os.listdir(UPLOAD_DIR):
            path = os.path.join(UPLOAD_DIR, filename)
            if os.path.isfile(path):
                files.append({
                    "name": filename,
                    "size": os.path.getsize(path),
                    "created": int(os.path.getmtime(path)),
                    "url": f"http://{request.host}/{UPLOAD_DIR}/{filename}",
                    "is_image": filename.lower().endswith(('.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg'))
                })
        
        # Sort by newest first
        files.sort(key=lambda x: x['created'], reverse=True)
        return jsonify({"status": "success", "files": files})

    # 2. API: Delete Files (POST ?action=delete)
    if request.method == "POST" and request.args.get("action") == "delete":
        if not authenticate():
            return jsonify({"status": "error", "message": "Invalid API Key"}), 403
        
        data = request.get_json()
        files_to_delete = data.get("files", [])
        deleted_count = 0
        
        for filename in files_to_delete:
            safe_name = os.path.basename(filename)
            path = os.path.join(UPLOAD_DIR, safe_name)
            if os.path.exists(path) and os.path.isfile(path):
                os.remove(path)
                deleted_count += 1
        
        return jsonify({"status": "success", "deleted": deleted_count})

    # 3. API: Upload File (POST /)
    if request.method == "POST" and "file" in request.files:
        if not authenticate():
            return jsonify({"status": "error", "message": "Invalid API Key"}), 403
            
        file = request.files["file"]
        if file.filename == "":
            return jsonify({"status": "error", "message": "No file selected"}), 400
            
        # Generate unique name
        ext = os.path.splitext(file.filename)[1].lower()
        new_filename = f"{int(time.time())}_{secrets.token_hex(4)}{ext}"
        target_path = os.path.join(UPLOAD_DIR, new_filename)
        
        file.save(target_path)
        
        return jsonify({
            "status": "success",
            "url": f"http://{request.host}/{UPLOAD_DIR}/{new_filename}",
            "filename": new_filename,
            "original_name": file.filename
        })

    # 4. Serve Simple Info Page (GET /)
    return render_template_string(f"""
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Standalone File Bridge</title>
        <style>
            body {{ font-family: sans-serif; background: #111; color: #eee; text-align: center; padding: 50px; }}
            .card {{ background: #1f2937; padding: 30px; border-radius: 15px; display: inline-block; border: 1px solid #374151; }}
            h1 {{ color: #6366f1; }}
            code {{ background: #000; padding: 2px 5px; border-radius: 4px; color: #10b981; }}
        </style>
    </head>
    <body>
        <div class="card">
            <h1>🚀 Standalone File Bridge</h1>
            <p>Server is running on: <code>http://{{ request.host }}</code></p>
            <p>Upload files via API: <code>POST /</code></p>
            <hr style="border: 0; border-top: 1px solid #374151; margin: 20px 0;">
            <p style="font-size: 0.8em; color: #9ca3af;">Use this IP:PORT in your AI settings</p>
        </div>
    </body>
    </html>
    """)

# Helper to serve files
@app.route(f"/{UPLOAD_DIR}/<path:filename>")
def downloaded_file(filename):
    return send_from_directory(UPLOAD_DIR, filename)

if __name__ == "__main__":
    print(f"--- Standalone File Bridge ---")
    print(f"API KEY: {API_KEY}")
    print(f"HOST: {HOST}")
    print(f"PORT: {PORT}")
    print(f"------------------------------")
    app.run(host=HOST, port=PORT)
