# FLA Polling for Flarum 2.0

![Flarum 2.0](https://img.shields.io/badge/Flarum-2.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

A lightweight real-time polling extension for Flarum 2.0 that provides instant updates for new discussions and notifications **without requiring WebSockets, SSH access, or Node.js**.

## 🚀 Features

- ✅ **No WebSocket required** - Works with standard HTTP requests
- ✅ **No SSH or Node.js** - Perfect for shared hosting
- ✅ **Self-hosted** - Complete control, no external dependencies
- ✅ **Lightweight** - Minimal server resources consumption
- ✅ **Real-time updates** - Check for new discussions and notifications every 10 seconds (configurable)
- ✅ **Visual notifications** - Banner for new discussions and badge for notification count
- ✅ **Zero configuration** - Install and it just works
- 🌍 **Multilingual support** - English and Italian included (easy to add more languages)

## 📋 How It Works

Instead of maintaining a persistent WebSocket connection, FLA Polling uses **HTTP polling**:

1. Every 10 seconds, the browser sends a lightweight AJAX request to the server
2. The server checks the database for the latest discussion ID and unread notification count
3. If there are changes, the UI updates automatically:
   - A banner appears when new discussions are posted
   - The notification badge updates with the new count

This approach is **extremely efficient** and works on any standard LAMP/LEMP stack.

### Performance Impact

For a forum with 100 concurrent users and 10-second polling interval:
- **10 HTTP requests per second** to your server
- Each request executes 2 simple database queries (less than 1ms each)
- Total overhead: **negligible** (equivalent to a few page loads per minute)

## 📦 Installation

### Via Composer (Recommended)

```bash
composer require peopleinside/fla-polling
