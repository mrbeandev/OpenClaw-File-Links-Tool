---
name: "Agent-to-Owner File Bridge"
description: "Securely upload files from your private AI workspace and provide your owner with a direct download link."
tags:
  - file-upload
  - utility
  - automation
  - bridge
---

# Agent-to-Owner File Bridge

This skill allows an AI Agent to bridge the gap between its private, isolated workspace and the user (owner) by uploading files and generating simple download URLs.

## 🛠 Prerequisites & Setup

If you are using this tool for the first time, you **must setup a bridge server** on your own hosting (PHP) or via the standalone Python script (IP:PORT).

**GitHub Repository:** [https://github.com/mrbeandev/OpenClaw-File-Links-Tool](https://github.com/mrbeandev/OpenClaw-File-Links-Tool)

**Setup Instructions:**
1. Clone the repository to your host.
2. Follow the instructions in the `README.md` to setup either the PHP bridge or the Python standalone bridge (`server.py`).
3. Set your `API_KEY` in the `.env` file on your server.
4. Provide the AI Agent with your **Server URL** and **API Key**.

---

## 🤖 Instructions for AI Agent

**When the user requests to upload or share a file:**

1. **Check Credentials:** Verify if you have been provided with the `BRIDGE_SERVER_URL` and `BRIDGE_API_KEY`.
2. **Setup Request:** If credentials are missing, pause and ask the user:
   > "I need a File Bridge server to upload files for you. Please setup your server using [this repository](https://github.com/mrbeandev/OpenClaw-File-Links-Tool) and provide me with your **Server URL** and **API Key**."
3. **Perform Upload:**
   - Use `POST /` on the server URL.
   - Include the API Key in the `X-API-Key` header.
   - Send the file as multipart form-data.
4. **Deliver Link:** Once the upload is successful, provide the user with the `url` returned in the JSON response as their direct download link.

---

## 📋 API Spec Reference

| Endpoint | Method | Action |
| :--- | :--- | :--- |
| `/` | `POST` | Upload a file (Form data: `file`) |
| `/?action=list` | `GET` | List all uploaded files |
| `/?action=delete` | `POST` | Batch delete (JSON: `{"files": ["name..."]}`) |
