#!/bin/sh
status=/status/tunnel-status.txt
mark=/project/ТУННЕЛЬ-ОБОРВАЛСЯ.txt
mkdir -p /status
while true; do
  metrics=$(wget -qO- --timeout=5 http://cloudflared-named:20241/metrics 2>/dev/null || true)
  conn=$(printf '%s\n' "$metrics" | awk '/^cloudflared_tunnel_ha_connections / { print $2; exit }')
  now=$(date '+%Y-%m-%d %H:%M:%S')
  if [ -z "$conn" ] || [ "$conn" = "0" ]; then
    printf 'DOWN %s\nТуннель оборвался. Запустите починить-туннель.bat\n' "$now" > "$status"
    printf 'Туннель оборвался %s\nЗапустите починить-туннель.bat в папке chatbot\n' "$now" > "$mark"
  else
    printf 'UP %s\nСоединений: %s\n' "$now" "$conn" > "$status"
    rm -f "$mark"
  fi
  sleep 15
done
