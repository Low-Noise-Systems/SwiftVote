#!/bin/bash

# Development Server Startup Script
# Starts PHP built-in server on localhost:8000 for local testing

echo "🚀 Starting PHP development server on localhost:8000"
echo "📝 Make sure DEBUG_MODE=true in php-survey-backend/config/.env"
echo ""
echo "Access points:"
echo "  - Main page: http://localhost:8000"
echo "  - Admin login: http://localhost:8000/admin/login.php"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

cd public && php -S localhost:8000