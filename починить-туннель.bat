@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo Перезапускаю только туннель. Остальные контейнеры не трогаю.
docker restart chatbot-cloudflared-named-1
echo Жду 20 секунд, пока соединение поднимется...
timeout /t 20 /nobreak >nul
docker logs chatbot-cloudflared-named-1 --tail 20
echo.
echo Если выше есть строка Registered tunnel connection, туннель снова жив.
pause
