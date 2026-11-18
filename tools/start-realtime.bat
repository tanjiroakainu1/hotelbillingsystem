@echo off
title Hotel Billing - Realtime Server
cd /d "%~dp0.."
echo Starting realtime WebSocket server on port 8081 (keep this window open)...
node tools\realtime-server.js
pause
