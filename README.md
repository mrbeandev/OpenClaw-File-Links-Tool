# 🚀 Agent-to-Owner File Bridge (API & Dashboard)

A specialized tool designed for **AI agents** to securely move files from their private, isolated workspaces to their owners. It provides agents with a simple REST API to upload results and generates **clean, public URLs** for owners to download those files instantly via a premium web dashboard.

[![clawhub](https://www.clawhub.ai/clawd-logo.png)](https://www.clawhub.ai/mrbeandev/file-links-tool)

**🌍 Deployed at:** `https://your-domain.com/upload/`

---

## 🛠️ 1. Quick Setup

### 1. PHP Setup (Shared Hosting/Domain)
1.  Place `index.php` in your web server's directory.
2.  Create a `.env` file from `.env.example`: `cp .env.example .env`.
3.  Set permissions: `chown -R www-data:www-data uploads/ && chmod 755 uploads/`.
4.  **Requirement:** Ensure `php-zip` extension is installed (`sudo apt install php-zip`).
5.  Edit `.env` to set your `API_KEY`.

### 2. Python Setup (Standalone/IP:PORT)
Perfect for users without a domain or PHP. Run this on your VPS or local machine.
1.  Create a `.env` file from `.env.example`: `cp .env.example .env`.
2.  Install Flask: `pip install flask`
3.  Run the server: `python server.py`
4.  Access via: `http://YOUR_SERVER_IP:5000`

### Configuration Constants
| Constant | Description |
| :--- | :--- |
| `API_KEY` | Secret key for auth. |
| `UPLOAD_DIR` | Directory to store files. |
| `PORT` | (Python only) Default: 5000. |

---

## �️ 2. Dashboard Usage

Navigate to your deployment URL in your browser for a premium management experience:

1.  **Auth:** Enter your API Key when prompted (saved locally for convenience).
2.  **Upload:** Drag & Drop files anywhere or click the upload zone.
3.  **Manage:**
    - Click the **Link Icon** to copy the direct URL.
    - Click the **Eye Icon** to view/preview the file (rich syntax highlighting for code).
    - **ZIP Inspection:** Open any ZIP to browse and view its inner files without downloading.
    - Select multiple files for **Batch Deletion**.
4.  **UI:** Fully responsive dark-mode interface with glassmorphic elements.

## 🤖 3. AI Agent Workflow

The primary goal of this bridge is to allow your AI agent to:
1.  **Extract** data or generate a file in its private environment.
2.  **Upload** that file via the `POST /index.php` endpoint.
3.  **Provide** the owner (you) with the generated `url` from the JSON response.

For exact implementation details, AI agents and MCP tools should refer to:
👉 **[api_instructions.txt](./api_instructions.txt)**