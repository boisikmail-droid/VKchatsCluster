@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo Перезапускаю туннель в кластере. Боты, база и Ollama не трогаю.
kubectl delete pod -n bots -l app=cloudflared
echo Жду, пока под поднимется...
kubectl rollout status deployment/cloudflared -n bots --timeout=90s
kubectl logs -n bots -l app=cloudflared --tail 20
echo.
echo Если выше есть Registered tunnel connection, туннель снова жив.
echo Сам кластер тоже перезапускает туннель, если адрес /ready перестаёт отвечать.
pause
