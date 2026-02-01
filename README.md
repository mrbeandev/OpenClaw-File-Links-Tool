# 🚀 Agent-to-Owner File Bridge (API & Dashboard)

A specialized tool designed for **AI agents** to securely move files from their private, isolated workspaces to their owners. It provides agents with a simple REST API to upload results and generates **clean, public URLs** for owners to download those files instantly via a premium web dashboard.

**🌍 Deployed at:** [https://mrbean.dev/upload/](https://mrbean.dev/upload/)

---

## 🛠️ 1. Quick Setup

### Installation
1.  Place `index.php` in your web server's public directory (e.g., `/var/www/html/upload/`).
2.  Ensure the directory is writable by the web server user:
    ```bash
    chown -R www-data:www-data /path/to/upload/
    chmod 755 /path/to/upload/
    ```
3.  Edit `index.php` to configure your credentials.

### Configuration Constants
| Constant | Description |
| :--- | :--- |
| `API_KEY` | The secret key required for all operations. |
| `UPLOAD_DIR` | Directory to store files (default: `uploads/`). |
| `BASE_URL` | Public URL prefix for generated links. |

---

## �️ 2. Dashboard Usage

Navigate to [https://mrbean.dev/upload/](https://mrbean.dev/upload/) in your browser for a premium management experience:

1.  **Auth:** Enter your API Key when prompted (saved locally for convenience).
2.  **Upload:** Drag & Drop files anywhere or click the upload zone.
3.  **Manage:**
    - Click the **Link Icon** to copy the direct URL.
    - Click the **Eye Icon** to view/preview the file.
    - Select multiple files for **Batch Deletion**.
4.  **UI:** Fully responsive dark-mode interface with glassmorphic elements.

## 🤖 3. AI Agent Workflow

The primary goal of this bridge is to allow your AI agent to:
1.  **Extract** data or generate a file in its private environment.
2.  **Upload** that file via the `POST /index.php` endpoint.
3.  **Provide** the owner (you) with the generated `url` from the JSON response.

For exact implementation details, AI agents and MCP tools should refer to:
👉 **[api_instructions.txt](./api_instructions.txt)**