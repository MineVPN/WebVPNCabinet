#!/bin/bash
#
# ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
# ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
# ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
# ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
# ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
# ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
# ══════════════════════════════════════════════════════════════════
#       V P N   H E A L T H C H E C K   D A E M O N   F I L E
# ══════════════════════════════════════════════════════════════════
#
# @category    VPN Subsystem
# @package     MineVPN\Server
# @version     5.0.0
# [WARNING]
# This source code is strictly proprietary and confidential.
# Unauthorized reproduction, distribution, or decompilation
# is strictly prohibited and heavily monitored.
# @copyright   2026 MineVPN Systems. All rights reserved.
# ══════════════════════════════════════════════════════════════════
#
# MineVPN Server — VPN Health Check / Daemon мониторинга VPN-туннелей
#
# Работает как long-running сервис под systemd (Type=simple, Restart=always).
# Главный цикл main_loop() крутится каждые 5с и проверяет состояние VPN.
#
# Что проверяет:
#   • ping через tun0 (жив ли туннель)
#   • ping через WAN (жив ли интернет провайдера — различает «VPN упал» от «ISP лёг»)
#   • VPN routing (fwmark для WireGuard, redirect-gateway для OpenVPN)
#   • iptables Kill Switch (DROP policy + LAN→WAN reject)
#   • IP leak (раз в 5 мин, только при full tunnel)
#
# Что делает при обнаружении проблемы:
#   • Сначала — restart текущего конфига (wg-quick / openvpn)
#   • Если не помогло — failover на backup конфиг (switch_to_config)
#   • self-heal: iptables (восстанавливает Kill Switch из rules.v4),
#     wg fwmark rule (восстанавливает policy routing без restart)
#   • Дедупликация событий (5 мин окно) — предотвращает спам в events.log
#
# Взаимодействует с:
#   • /etc/systemd/system/vpn-healthcheck.service — systemd unit запускает этот скрипт от root
#   • update.sh — синхронизирует скрипт (md5 hash) и перезапускает daemon при изменениях
#
# Читает:
#   • /var/www/settings           — vpnchecker, autoupvpn, failover, failover_first
#   • /var/www/minevpn-state      — STATE, ACTIVE_ID, PRIMARY_ID, ACTIVATED_BY
#   • /var/www/vpn-configs/configs.json + *.conf — список конфигов для failover
#   • /etc/wireguard/tun0.conf, /etc/openvpn/tun0.conf — активный конфиг
#   • /etc/minevpn.conf           — WAN/LAN интерфейсы (создаётся Installer.sh / update.sh)
#
# Пишет:
#   • /var/log/minevpn/vpn.log    — детальный лог daemon (5 MB ротация)
#   • /var/log/minevpn/events.log — события для UI (vpn_down/failover/recovery_attempt/auto_start)
#   • /var/www/minevpn-state      — атомарное обновление состояния (tmp → mv → chmod 666)
#   • /etc/iptables/rules.v4      — при самовосстановлении Kill Switch
#   • /etc/wireguard/tun0.conf, /etc/openvpn/tun0.conf — при switch_to_config (failover)
#
# Вызывает:
#   • systemctl start/stop/enable/disable wg-quick@tun0
#   • systemctl start/stop/enable/disable openvpn@tun0
#
# Использует утилиты: ping, iptables/iptables-restore, ip rule/route, wg show, curl, php, flock.
# ==================================================================


INTERFACE="tun0"
SETTINGS="/var/www/settings"
VPN_STATE_FILE="/var/www/minevpn-state"
LOG="/var/log/minevpn/vpn.log"
EVENTS="/var/log/minevpn/events.log"
MINEVPN_CONF="/etc/minevpn.conf"
CONFIGS_JSON="/var/www/vpn-configs/configs.json"
CONFIGS_DIR="/var/www/vpn-configs"
MAX_LOG=5242880       # 5 MB для vpn.log
MAX_EVENTS=262144     # 256 KB для events.log → ~3000 событий

PING_HOSTS=("8.8.8.8" "1.1.1.1" "9.9.9.9")
IP_SERVICES=("ifconfig.me" "icanhazip.com" "api.ipify.org")

PING_INTERVAL=5       # Проверка ping (с)
PING_TIMEOUT=2        # Таймаут одного ping (с)
IPTABLES_INTERVAL=15  # Проверка iptables (с)
LEAK_INTERVAL=300     # Проверка IP leak (с)
COOLDOWN_INITIAL=10   # Начальный cooldown (с)
COOLDOWN_MAX=60       # Максимальный cooldown (с)
WG_POLL_MAX=10        # Макс ожидание WireGuard (с)
OVPN_POLL_MAX=20      # Макс ожидание OpenVPN (с)
WARMUP_TIMEOUT=120    # Стартовый warmup в main_loop — ждём стабильности VPN перед recovery (с)
EVENT_DEDUP_WINDOW=300  # Не повторяем vpn_down/recovery_attempt с той же причиной чаще (с)

# ═══════════════════════════════════════════════════════
# ЛОГИРОВАНИЕ
# ═══════════════════════════════════════════════════════

log() {
    local ts
    ts=$(date '+%Y-%m-%d %H:%M:%S')
    if [ -f "$LOG" ]; then
        local sz
        sz=$(stat -c%s "$LOG" 2>/dev/null || echo 0)
        [ "$sz" -gt "$MAX_LOG" ] && mv "$LOG" "$LOG.old"
    fi
    echo "[$ts] [$1] $2" >> "$LOG"
    logger -t "MineVPN" "[$1] $2"
}

# log_event TYPE [F1] [F2] [F3]  —  одна строка в events.log
# Формат: TIME|TYPE|F1|F2|F3
# Поля не могут содержать разделители — они заменяются на безопасные аналоги.
log_event() {
    local ts type
    ts=$(date '+%Y-%m-%d %H:%M:%S')
    type="$1"
    shift
    local line="${ts}|${type}"
    local f
    for f in "$@"; do
        # sed: заменяем | на /, а newline/CR на пробел — безопасно без bash-quoting сюрпризов
        f=$(printf '%s' "$f" | tr '|\n\r' '/  ')
        line="${line}|${f}"
    done
    echo "$line" >> "$EVENTS"
    # Ротация: если файл > MAX_EVENTS → оставляем последние 500 строк
    local sz
    sz=$(stat -c%s "$EVENTS" 2>/dev/null || echo 0)
    if [ "$sz" -gt "$MAX_EVENTS" ]; then
        tail -n 500 "$EVENTS" > "${EVENTS}.tmp" && mv -f "${EVENTS}.tmp" "$EVENTS"
    fi
}

# ═══════════════════════════════════════════════════════
# VPN STATE (/var/www/minevpn-state)
# ═══════════════════════════════════════════════════════

read_vpn_state() {
    VPN_STATE="stopped"
    ACTIVE_ID=""
    PRIMARY_ID=""
    ACTIVATED_BY=""
    [ ! -f "$VPN_STATE_FILE" ] && return
    local line key val
    while IFS= read -r line; do
        [[ "$line" =~ ^([A-Z_]+)=(.*)$ ]] || continue
        key="${BASH_REMATCH[1]}"
        val="${BASH_REMATCH[2]}"
        case "$key" in
            STATE)        VPN_STATE="$val" ;;
            ACTIVE_ID)    ACTIVE_ID="$val" ;;
            PRIMARY_ID)   PRIMARY_ID="$val" ;;
            ACTIVATED_BY) ACTIVATED_BY="$val" ;;
        esac
    done < "$VPN_STATE_FILE"
}

save_vpn_state() {
    # Атомарная запись: tmp → rename → chmod 666.
    # mv -f переносит ownership/perms tmp-файла (созданного от root с umask 022 = 0644)
    # поверх оригинала — поэтому после mv файл становится root:root 0644 и PHP (www-data)
    # получает Permission denied. Явный chmod 666 после mv исправляет это.
    local tmp="${VPN_STATE_FILE}.hc.tmp"
    if printf 'STATE=%s\nACTIVE_ID=%s\nPRIMARY_ID=%s\nACTIVATED_BY=%s\n' \
        "$VPN_STATE" "$ACTIVE_ID" "$PRIMARY_ID" "$ACTIVATED_BY" > "$tmp" \
        && mv -f "$tmp" "$VPN_STATE_FILE"; then
        chmod 666 "$VPN_STATE_FILE" 2>/dev/null || true
    else
        rm -f "$tmp"
    fi
}

# ═══════════════════════════════════════════════════════
# ВСПОМОГАТЕЛЬНЫЕ
# ═══════════════════════════════════════════════════════

get_vpn_type() {
    [ -f "/etc/wireguard/${INTERFACE}.conf" ] && echo "wg" && return
    [ -f "/etc/openvpn/${INTERFACE}.conf" ] && echo "ovpn" && return
    echo ""
}

get_config_name() {
    local type
    type=$(get_vpn_type)
    if [ "$type" = "wg" ]; then
        grep -oP 'Endpoint\s*=\s*\K[^:]+' /etc/wireguard/${INTERFACE}.conf 2>/dev/null | head -1 || echo "wg-$INTERFACE"
    elif [ "$type" = "ovpn" ]; then
        grep -oP 'remote\s+\K\S+' /etc/openvpn/${INTERFACE}.conf 2>/dev/null | head -1 || echo "ovpn-$INTERFACE"
    else
        echo "none"
    fi
}

check_settings()         { [ -f "$SETTINGS" ] && grep -q "^vpnchecker=true$" "$SETTINGS"; }
check_autoup()           { [ -f "$SETTINGS" ] && grep -q "^autoupvpn=true$" "$SETTINGS"; }
check_failover_enabled() { [ -f "$SETTINGS" ] && grep -q "^failover=true$" "$SETTINGS"; }
# failover_first=true — агрессивный режим: сразу прыжок на резерв, без restart активного и без возврата на primary.
# failover_first=false (дефолт) — мягкий режим: сначала restart активного, потом возврат на primary (если мы на backup),
#                                          и только потом failover на следующий backup.
# ИМЕНОВАНИЕ в v5: раньше ключ назывался try_primary_first с инверсной семантикой (включён=мягкий).
# Переименовано в v5 в failover_first — теперь each ON = больше агрессивности (последовательная mental model
# с vpnchecker → autoupvpn → failover → failover_first). Старый ключ в /var/www/settings больше не используется.
check_failover_first()   { [ -f "$SETTINGS" ] && grep -q "^failover_first=true$" "$SETTINGS"; }
check_iface()            { ip link show "$INTERFACE" &>/dev/null; }
check_ip()               { ip -4 addr show "$INTERFACE" 2>/dev/null | grep -q "inet "; }
check_wan_has_ip()       { [ -z "$WAN_IF" ] && return 0; ip -4 addr show "$WAN_IF" 2>/dev/null | grep -q "inet "; }

# Регистрация "последняя причина падения" — передаётся в vpn_down/recovery_attempt/failover.
# Дедупликация: одна и та же причина пишется в events.log не чаще раз в EVENT_DEDUP_WINDOW.
# Предотвращает спам когда проблема повторяется каждую итерацию (напр. fwmark rule не
# восстанавливается — main_loop каждые 5с видит failed routing и без дедупа писал бы
# vpn_down каждый раз). При восстановлении VPN (vpn_ok=0→1) дедуп state сбрасывается.
note_down() {
    local reason="$1"
    LAST_DOWN_REASON="$reason"

    local now
    now=$(date +%s)
    if [ "$LAST_VPN_DOWN_REASON" = "$reason" ] && \
       [ $((now - LAST_VPN_DOWN_TIME)) -lt "$EVENT_DEDUP_WINDOW" ]; then
        return  # дубль с той же причиной — не пишем
    fi
    LAST_VPN_DOWN_REASON="$reason"
    LAST_VPN_DOWN_TIME="$now"
    log_event vpn_down "$ACTIVE_ID" "$reason"
}

# Дедуплицированная запись recovery_attempt — та же логика что у note_down.
# RECOVERY_ATTEMPT_DEDUPED — глобальный маркер для парного recovery_succeeded:
#   0 = событие реально записано в events.log
#   1 = вызов был дедуплицирован (та же причина за <5 мин)
# do_recovery после успешного Шага 1 проверяет этот маркер — recovery_succeeded
# пишется ТОЛЬКО когда attempt был записан, иначе получим orphan-success без
# open-события (вид в журнале: vpn_down → ... → recovered, без "попытки" между).
log_recovery_attempt() {
    local reason="${1:-restart}"
    local now
    now=$(date +%s)
    if [ "$LAST_RECOVERY_REASON" = "$reason" ] && \
       [ $((now - LAST_RECOVERY_TIME)) -lt "$EVENT_DEDUP_WINDOW" ]; then
        RECOVERY_ATTEMPT_DEDUPED=1
        return
    fi
    RECOVERY_ATTEMPT_DEDUPED=0
    LAST_RECOVERY_REASON="$reason"
    LAST_RECOVERY_TIME="$now"
    log_event recovery_attempt "$ACTIVE_ID" "$reason"
}

# Дедуплицированная запись firewall_restored — закрытие vpn_down с причиной
# "iptables правила потеряны". Если Kill Switch падает циклически (ошибка в
# rules.v4 или другое сторонее ПО затирает правила) — без дедупа в журнал
# летел бы спам. 5-минутное окно — достаточно для одного представительского
# события за инцидент, но не блокирует надолго если проблема реально новая.
log_event_firewall_restored() {
    local now_fw
    now_fw=$(date +%s)
    if [ $((now_fw - LAST_FIREWALL_RESTORED_TIME)) -lt "$EVENT_DEDUP_WINDOW" ]; then
        return
    fi
    LAST_FIREWALL_RESTORED_TIME="$now_fw"
    log_event firewall_restored
}

# Сброс дедуп-стейта — вызывается при успешном восстановлении VPN.
# После этого следующее падение запишется как новое событие, не дубль.
reset_event_dedup() {
    LAST_VPN_DOWN_REASON=""
    LAST_VPN_DOWN_TIME=0
    LAST_RECOVERY_REASON=""
    LAST_RECOVERY_TIME=0
}

# ═══════════════════════════════════════════════════════
# ПЕРЕКЛЮЧЕНИЕ КОНФИГА
# ═══════════════════════════════════════════════════════

switch_to_config() {
    local target_id="$1"
    [ -z "$target_id" ] && return 1
    [ ! -f "$CONFIGS_JSON" ] && return 1

    local cfg_info
    cfg_info=$(php -r '
        $configs=json_decode(file_get_contents($argv[1]),true);
        if(isset($configs[$argv[2]])){
            $c=$configs[$argv[2]];
            echo ($c["filename"]??"")."|".($c["type"]??"unknown")."|".($c["name"]??"");
        }
    ' -- "$CONFIGS_JSON" "$target_id" 2>/dev/null)
    [ -z "$cfg_info" ] && return 1

    local cfg_file cfg_type cfg_name
    IFS='|' read -r cfg_file cfg_type cfg_name <<< "$cfg_info"
    [ -z "$cfg_file" ] && return 1

    local source_path="${CONFIGS_DIR}/${cfg_file}"
    [ ! -f "$source_path" ] && { log "ERR" "Файл $source_path не найден"; return 1; }

    log "INFO" "Переключение на '${cfg_name}' (${cfg_type})"

    systemctl stop "wg-quick@${INTERFACE}" 2>/dev/null
    systemctl stop "openvpn@${INTERFACE}" 2>/dev/null
    sleep 1

    rm -f "/etc/wireguard/${INTERFACE}.conf" "/etc/openvpn/${INTERFACE}.conf"

    local poll_max=$WG_POLL_MAX
    if [ "$cfg_type" = "wireguard" ]; then
        cp "$source_path" "/etc/wireguard/${INTERFACE}.conf"
        chmod 600 "/etc/wireguard/${INTERFACE}.conf"
        systemctl enable "wg-quick@${INTERFACE}" 2>/dev/null
        systemctl start "wg-quick@${INTERFACE}" 2>/dev/null
    else
        cp "$source_path" "/etc/openvpn/${INTERFACE}.conf"
        chmod 600 "/etc/openvpn/${INTERFACE}.conf"
        systemctl disable "wg-quick@${INTERFACE}" 2>/dev/null
        systemctl enable "openvpn@${INTERFACE}" 2>/dev/null
        systemctl start "openvpn@${INTERFACE}" 2>/dev/null
        poll_max=$OVPN_POLL_MAX
    fi

    local i=0
    while [ $i -lt $poll_max ]; do
        sleep 1; i=$((i + 1))
        if check_iface && check_ip && ping_vpn; then
            log "OK" "'${cfg_name}' поднят за ${i}с"
            return 0
        fi
    done

    log "ERR" "'${cfg_name}' не поднялся за ${poll_max}с"
    return 1
}

# ═══════════════════════════════════════════════════════
# RECOVERY — ВОССТАНОВЛЕНИЕ СОЕДИНЕНИЯ
# ═══════════════════════════════════════════════════════

reset_after_recovery() {
    local now
    now=$(date +%s)
    if [ "$LAST_RESTART_OK" -gt 0 ] && [ $((now - LAST_RESTART_OK)) -lt 120 ]; then
        COOLDOWN=$COOLDOWN_INITIAL
        COOLDOWN_UNTIL=$((now + COOLDOWN_INITIAL))
        log "WARN" "Частые перезапуски (flapping) — cooldown ${COOLDOWN_INITIAL}с"
    else
        COOLDOWN=0; COOLDOWN_UNTIL=0
    fi
    RESTART_FAILS=0; LAST_RESTART_OK=$now
    # После успеха recovery сбрасываем дедуп — следующее падение будет новым событием
    reset_event_dedup
}

do_recovery() {
    VPN_STATE="recovering"
    save_vpn_state
    log "INFO" "Восстановление VPN..."

    # --- Шаг 1: restart текущего конфига ---
    # skip_restart=1 — пропускаем restart, сразу идём на failover.
    # Срабатывает когда failover_first=true (агрессивный режим) И есть backup-конфиги для failover.
    # Если backup-ов нет — restart всё равно имеет смысл (больше нечего пробовать).
    local skip_restart=0
    if check_failover_first && check_failover_enabled && [ -f "$CONFIGS_JSON" ]; then
        local has_backups
        has_backups=$(php -r '
            $c=json_decode(file_get_contents($argv[1]),true);
            if(!is_array($c))exit(1);
            foreach($c as $cid=>$cfg){
                if(($cfg["role"]??"")==="backup" && $cid!==$argv[2]){echo "1";exit;}
                if(($cfg["role"]??"")==="primary" && $cid!==$argv[2]){echo "1";exit;}
            }
        ' -- "$CONFIGS_JSON" "$ACTIVE_ID" 2>/dev/null)
        [ "$has_backups" = "1" ] && skip_restart=1
    fi

    local type
    type=$(get_vpn_type)
    if [ -n "$type" ] && [ "$skip_restart" -eq 0 ]; then
        local cur_name
        cur_name=$(get_config_name)
        log "INFO" "Перезапуск текущего конфига ($cur_name, $type)..."
        log_recovery_attempt "${LAST_DOWN_REASON:-restart}"
        local poll_max
        if [ "$type" = "wg" ]; then
            systemctl restart "wg-quick@${INTERFACE}" 2>/dev/null
            poll_max=$WG_POLL_MAX
        else
            systemctl restart "openvpn@${INTERFACE}" 2>/dev/null
            poll_max=$OVPN_POLL_MAX
        fi
        local i=0
        while [ $i -lt $poll_max ]; do
            sleep 1; i=$((i + 1))
            if check_iface && check_ip && ping_vpn; then
                log "OK" "Текущий конфиг восстановлен за ${i}с"
                VPN_STATE="running"; save_vpn_state
                # Закрывающее событие для recovery_attempt — юзер видит happy path в журнале
                # (vpn_down → recovery_attempt → recovery_succeeded). Без этого после
                # "попытки" наступала тишина и юзер не знал что всё OK.
                # Пишем ТОЛЬКО если attempt был реально записан (не дедуплицирован),
                # иначе получим orphan-success без open-события.
                if [ "${RECOVERY_ATTEMPT_DEDUPED:-0}" != "1" ]; then
                    log_event recovery_succeeded "$ACTIVE_ID" "${LAST_DOWN_REASON:-restart}"
                fi
                reset_after_recovery
                # Предотвращаем дубль-события: main_loop не напишет auto_start после recovery
                DAEMON_JUST_STARTED=0
                return 0
            fi
        done
        RESTART_FAILS=$((RESTART_FAILS + 1))
        log "WARN" "Restart текущего конфига не помог (попытка $RESTART_FAILS)"
    elif [ "$skip_restart" -eq 1 ]; then
        log "INFO" "Пропуск restart — сразу failover (failover_first=true)"
    fi

    # --- Шаг 2: возврат на primary (в мягком режиме: failover_first=false) если мы на backup ---
    # primary_tried_in_step2 — флаг чтобы в Шаге 3 (failover loop) НЕ дублировать попытку primary.
    # Без флага в мягком режиме primary пробовался бы ДВАЖДЫ (Шаг 2 и в начале Шага 3) — это —10-20с лишней задержки
    # перед переходом на реальный backup. Теперь Шаг 3 получает этот флаг как 4-й аргумент и исключает primary из backup_list.
    local primary_tried_in_step2=0
    if ! check_failover_first && [ -n "$PRIMARY_ID" ] && [ "$ACTIVE_ID" != "$PRIMARY_ID" ]; then
        log "INFO" "Попытка вернуться на основной конфиг..."
        primary_tried_in_step2=1
        if switch_to_config "$PRIMARY_ID"; then
            ACTIVE_ID="$PRIMARY_ID"
            ACTIVATED_BY="manual"
            VPN_STATE="running"; save_vpn_state
            reset_after_recovery
            log_event failover_restored "$PRIMARY_ID"
            update_configs_json "$PRIMARY_ID" "manual"
            DAEMON_JUST_STARTED=0
            return 0
        fi
    fi

    # --- Шаг 3: failover — перебираем backup-конфиги по priority ---
    if ! check_failover_enabled; then
        log "INFO" "Failover отключён в настройках"
    elif [ ! -f "$CONFIGS_JSON" ]; then
        log "ERR" "configs.json не найден: $CONFIGS_JSON"
    else
        log "INFO" "Failover: поиск backup-конфигов (текущий ACTIVE_ID=$ACTIVE_ID, primary_tried_in_step2=$primary_tried_in_step2)..."
        # primary_tried_in_step2 передаём в PHP как 4-й аргумент — если 1, исключаем primary из backup_list
        # (иначе в мягком режиме primary пробовался бы дважды: Шаг 2 + первый в Шаге 3).
        local backup_list
        backup_list=$(php -r '
            $configs=json_decode(file_get_contents($argv[1]),true);
            if(!is_array($configs))exit(1);
            $cur=$argv[2];
            $exclude_primary=($argv[3]==="1");
            uasort($configs,function($a,$b){return ($a["priority"]??99)-($b["priority"]??99);});
            $backups=[];
            foreach($configs as $cid=>$cfg){
                if(($cfg["role"]??"")==="backup" && $cid!==$cur)$backups[]=$cid;
            }
            if(!$exclude_primary){
                foreach($configs as $cid=>$cfg){
                    if(($cfg["role"]??"")==="primary" && $cid!==$cur && !in_array($cid,$backups)){
                        array_unshift($backups,$cid);
                    }
                }
            }
            foreach($backups as $cid)echo $cid."\n";
        ' -- "$CONFIGS_JSON" "$ACTIVE_ID" "$primary_tried_in_step2" 2>/dev/null)
        if [ -z "$backup_list" ]; then
            log "WARN" "Нет доступных backup-конфигов"
        else
            log "INFO" "Найдены backup-конфиги: $(echo "$backup_list" | tr '\n' ' ')"
            local old_id="$ACTIVE_ID"
            while IFS= read -r backup_id; do
                [ -z "$backup_id" ] && continue
                log "INFO" "Failover: пробуем конфиг $backup_id..."
                if switch_to_config "$backup_id"; then
                    ACTIVE_ID="$backup_id"
                    ACTIVATED_BY="failover"
                    VPN_STATE="running"; save_vpn_state
                    reset_after_recovery
                    log_event failover "$backup_id" "$old_id" "${LAST_DOWN_REASON:-unknown}"
                    update_configs_json "$backup_id" "failover"
                    DAEMON_JUST_STARTED=0
                    return 0
                fi
            done <<< "$backup_list"
        fi
    fi

    # --- Шаг 4: все fail → cooldown ---
    log "ERR" "Все конфиги недоступны"
    log_event recovery_failed "${LAST_DOWN_REASON:-all configs unreachable}"
    VPN_STATE="recovering"; save_vpn_state

    COOLDOWN=$((COOLDOWN + COOLDOWN_INITIAL))
    [ "$COOLDOWN" -gt "$COOLDOWN_MAX" ] && COOLDOWN=$COOLDOWN_MAX
    local now
    now=$(date +%s)
    COOLDOWN_UNTIL=$((now + COOLDOWN))
    log "INFO" "Cooldown ${COOLDOWN}с"
    return 1
}

# Обновление configs.json (last_used, activated_by) с flock
update_configs_json() {
    local cfg_id="$1" by="$2"
    (
        flock -w 5 200 || { log "WARN" "flock configs.json timeout"; return; }
        php -r '
            $f=$argv[1]; $id=$argv[2]; $by=$argv[3];
            $data=json_decode(file_get_contents($f),true);
            if(!is_array($data))exit(1);
            foreach($data as $cid=>&$cfg){
                if(($cfg["activated_by"]??"")==="failover")$cfg["activated_by"]="";
            } unset($cfg);
            if(isset($data[$id])){
                $data[$id]["last_used"]=date("Y-m-d H:i:s");
                $data[$id]["activated_by"]=$by;
            }
            file_put_contents($f,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        ' -- "$CONFIGS_JSON" "$cfg_id" "$by" 2>/dev/null || true
    ) 200>"${CONFIGS_JSON}.lock"
}

# ═══════════════════════════════════════════════════════
# ИНТЕРФЕЙСЫ — WAN/LAN (перечитываются раз в 5 мин)
# ═══════════════════════════════════════════════════════

load_interfaces() {
    WAN_IF=""; LAN_IF=""
    # Primary source — /etc/minevpn.conf (создаётся Installer.sh::finalize и update.sh::v5).
    if [ -f "$MINEVPN_CONF" ]; then
        WAN_IF=$(grep "^WAN=" "$MINEVPN_CONF" 2>/dev/null | cut -d= -f2)
        LAN_IF=$(grep "^LAN=" "$MINEVPN_CONF" 2>/dev/null | cut -d= -f2)
    fi
    # Failsafe — если /etc/minevpn.conf отсутствует или повреждён: live system inspection.
    # ВАЖНО: НЕ парсим netplan yaml — порядок интерфейсов в нём может различаться (v4 имел
    # LAN первым, v5 имеет WAN первым), и head -1 давал бы WAN_IF=LAN_IF на v4-серверах.
    # ip-команды читают актуальное состояние ядра напрямую, не зависят от порядка в файлах.
    [ -z "$LAN_IF" ] && LAN_IF=$(ip -4 addr show 2>/dev/null | grep "10\.10\.1\.1/" | awk '{print $NF}')
    [ -z "$WAN_IF" ] && WAN_IF=$(ip route show default 2>/dev/null | grep -v "dev tun\|dev wg" | grep -oP 'dev \K[^ ]+' | head -1)
    [ -z "$LAN_IF" ] && log "WARN" "Не удалось определить LAN интерфейс"
    [ -z "$WAN_IF" ] && log "WARN" "Не удалось определить WAN интерфейс"
}

is_full_tunnel() {
    ip route show default 2>/dev/null | grep -q "dev $INTERFACE" && return 0
    if ip rule show 2>/dev/null | grep -q "fwmark.*lookup"; then
        local t
        t=$(ip rule show 2>/dev/null | grep "fwmark" | grep -oP 'lookup \K\d+' | head -1)
        [ -n "$t" ] && ip route show table "$t" 2>/dev/null | grep -q "dev $INTERFACE" && return 0
    fi
    local vt
    vt=$(get_vpn_type)
    [ "$vt" = "wg" ] && grep -q "AllowedIPs.*0\.0\.0\.0/0" /etc/wireguard/${INTERFACE}.conf 2>/dev/null && return 0
    [ "$vt" = "ovpn" ] && grep -q "redirect-gateway" /etc/openvpn/${INTERFACE}.conf 2>/dev/null && return 0
    return 1
}

# ═══════════════════════════════════════════════════════
# ПАРАЛЛЕЛЬНЫЙ PING (3 хоста, макс 2с)
# ═══════════════════════════════════════════════════════

ping_vpn() {
    local tmpdir
    tmpdir=$(mktemp -d /tmp/hc-ping.XXXX)
    local pids=()
    local h
    for h in "${PING_HOSTS[@]}"; do
        ( ping -c 1 -W "$PING_TIMEOUT" -I "$INTERFACE" "$h" &>/dev/null && touch "$tmpdir/ok" ) &
        pids+=($!)
    done
    wait "${pids[@]}" 2>/dev/null
    local result=1
    [ -f "$tmpdir/ok" ] && result=0
    rm -rf "$tmpdir"
    return $result
}

# Ping через WAN-интерфейс — проверяет жив ли интернет провайдера, минуя VPN.
# Вызывается только когда ping через tun0 не прошёл — чтобы различить "VPN упал"
# от "у провайдера лёг интернет". Если WAN_IF не определён — предполагаем ok
# (не можем проверить, поэтому идём по старому флоу и делаем recovery как раньше).
# Kill Switch (iptables FORWARD) не мешает: наш ping из OUTPUT chain хоста, не из FORWARD.
ping_wan() {
    [ -z "$WAN_IF" ] && return 0
    check_wan_has_ip || return 1
    local tmpdir
    tmpdir=$(mktemp -d /tmp/hc-wan-ping.XXXX)
    local pids=()
    local h
    for h in "${PING_HOSTS[@]}"; do
        ( ping -c 1 -W "$PING_TIMEOUT" -I "$WAN_IF" "$h" &>/dev/null && touch "$tmpdir/ok" ) &
        pids+=($!)
    done
    wait "${pids[@]}" 2>/dev/null
    local result=1
    [ -f "$tmpdir/ok" ] && result=0
    rm -rf "$tmpdir"
    return $result
}

# ═══════════════════════════════════════════════════════
# ПЕРЕЗАПУСК VPN (вызывается из main_loop)
# ═══════════════════════════════════════════════════════

restart_vpn() {
    read_vpn_state
    [ "$VPN_STATE" = "stopped" ] && return 1
    [ "$VPN_STATE" = "restarting" ] && return 1

    if ! check_wan_has_ip; then
        WAN_WAS_DOWN=1; return 1
    fi

    local now
    now=$(date +%s)
    if [ "$COOLDOWN_UNTIL" -gt 0 ] && [ "$now" -lt "$COOLDOWN_UNTIL" ]; then
        return 1
    fi

    RESTART_COUNT=$((RESTART_COUNT + 1))
    do_recovery
    return $?
}

# ═══════════════════════════════════════════════════════
# ПРОВЕРКА VPN ROUTING
# ═══════════════════════════════════════════════════════

check_vpn_routing() {
    local type
    type=$(get_vpn_type)

    if [ "$type" = "wg" ]; then
        grep -q "AllowedIPs.*0\.0\.0\.0/0" /etc/wireguard/${INTERFACE}.conf 2>/dev/null || return 0
        local fwmark
        fwmark=$(wg show "$INTERFACE" fwmark 2>/dev/null)
        [ -z "$fwmark" ] || [ "$fwmark" = "off" ] && return 0
        local hex_fwmark
        hex_fwmark=$(printf "0x%x" "$fwmark" 2>/dev/null)
        if ip rule show 2>/dev/null | grep -qE "fwmark\s+($fwmark|$hex_fwmark)"; then
            return 0
        fi

        # Self-heal: пытаемся восстановить fwmark rule напрямую (как wg-quick PostUp).
        # Эта ситуация происходит когда carrier-down на WAN или другой network event
        # удалил policy routing rules. wg-quick НЕ восстанавливает их сам — восстанавливаем
        # вручную, без дорогого перезапуска всего VPN и без записи vpn_down в events.log.
        log "WARN" "WG fwmark rule пропало, пробую восстановить напрямую (fwmark=$fwmark)..."
        ip -4 rule add not fwmark "$fwmark" table "$fwmark" 2>/dev/null
        ip -4 rule add table main suppress_prefixlength 0 2>/dev/null

        if ip rule show 2>/dev/null | grep -qE "fwmark\s+($fwmark|$hex_fwmark)"; then
            log "OK" "WG fwmark rule восстановлено напрямую (без перезапуска VPN)"
            return 0
        fi

        log "CRIT" "WireGuard fwmark rule потеряно и не восстанавливается! (fwmark=$fwmark)"
        note_down "WG fwmark rule потеряно"
        return 1
    elif [ "$type" = "ovpn" ]; then
        grep -q "redirect-gateway" /etc/openvpn/${INTERFACE}.conf 2>/dev/null || return 0
        if ip route show 2>/dev/null | grep -q "0\.0\.0\.0/1.*dev $INTERFACE"; then
            return 0
        fi
        log "CRIT" "OpenVPN маршруты потеряны! (нет 0.0.0.0/1 через $INTERFACE)"
        note_down "OVPN маршруты потеряны"
        return 1
    fi
    return 0
}

# ═══════════════════════════════════════════════════════
# IPTABLES self-heal
# ═══════════════════════════════════════════════════════

check_iptables() {
    local now
    now=$(date +%s)
    [ $((now - LAST_IPTABLES_CHECK)) -lt "$IPTABLES_INTERVAL" ] && return 0
    LAST_IPTABLES_CHECK=$now

    [ -z "$LAN_IF" ] && return 0

    local bad=0
    local policy
    policy=$(iptables -L FORWARD -n 2>/dev/null | head -1 | grep -oP '(?<=policy )\w+')
    [ "$policy" != "DROP" ] && bad=1
    [ "$bad" -eq 0 ] && ! iptables -t nat -C POSTROUTING -o tun0 -s 10.10.1.0/20 -j MASQUERADE 2>/dev/null && bad=1
    [ "$bad" -eq 0 ] && ! iptables -C FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT 2>/dev/null && bad=1
    [ "$bad" -eq 0 ] && return 0

    log "WARN" "Восстановление iptables Kill Switch..."
    note_down "iptables правила потеряны"

    if [ -f /etc/iptables/rules.v4 ] && [ -s /etc/iptables/rules.v4 ]; then
        if iptables-restore < /etc/iptables/rules.v4 2>/dev/null; then
            log "OK" "iptables восстановлены из rules.v4"
            log_event_firewall_restored
            return 0
        fi
    fi

    iptables -P FORWARD DROP; iptables -F FORWARD
    iptables -A FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT
    iptables -A FORWARD -i tun0 -o "$LAN_IF" -m state --state RELATED,ESTABLISHED -j ACCEPT
    iptables -A FORWARD -i "$LAN_IF" -o "$LAN_IF" -j ACCEPT
    [ -n "$WAN_IF" ] && iptables -A FORWARD -i "$LAN_IF" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable
    iptables -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
    iptables -t nat -C POSTROUTING -o tun0 -s 10.10.1.0/20 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -o tun0 -s 10.10.1.0/20 -j MASQUERADE
    iptables -C INPUT -i lo -j ACCEPT 2>/dev/null || iptables -A INPUT -i lo -j ACCEPT
    iptables -C INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || \
        iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
    iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || iptables -A INPUT -p tcp --dport 80 -j ACCEPT
    iptables -C INPUT -p tcp --dport 22 -j ACCEPT 2>/dev/null || iptables -A INPUT -p tcp --dport 22 -j ACCEPT
    if [ -n "$LAN_IF" ]; then
        local p proto port
        for p in "udp:53" "tcp:53" "udp:67"; do
            proto=${p%%:*}; port=${p##*:}
            iptables -C INPUT -i "$LAN_IF" -p "$proto" --dport "$port" -j ACCEPT 2>/dev/null || \
                iptables -A INPUT -i "$LAN_IF" -p "$proto" --dport "$port" -j ACCEPT
        done
    fi
    iptables-save > /etc/iptables/rules.v4 2>/dev/null
    log "OK" "iptables Kill Switch восстановлен"
    log_event_firewall_restored
}

# ═══════════════════════════════════════════════════════
# ПРОВЕРКА IP LEAK (раз в 5 мин, только full tunnel)
# ═══════════════════════════════════════════════════════

check_leak() {
    local now
    now=$(date +%s)
    [ $((now - LAST_LEAK_CHECK)) -lt "$LEAK_INTERVAL" ] && return 0
    LAST_LEAK_CHECK=$now

    is_full_tunnel || return 0

    local vpn_ip="" def_ip="" s
    for s in "${IP_SERVICES[@]}"; do
        vpn_ip=$(curl -s --interface "$INTERFACE" --max-time 5 "$s" 2>/dev/null)
        [[ "$vpn_ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && break || vpn_ip=""
    done
    [ -z "$vpn_ip" ] && return 0

    for s in "${IP_SERVICES[@]}"; do
        def_ip=$(curl -s --max-time 5 "$s" 2>/dev/null)
        [[ "$def_ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && break || def_ip=""
    done
    [ -z "$def_ip" ] && return 0
    [ "$def_ip" = "$vpn_ip" ] && return 0

    log "CRIT" "IP УТЕЧКА! Default:$def_ip VPN:$vpn_ip"
    note_down "IP утечка: def=$def_ip vpn=$vpn_ip"
    return 1
}

# ═══════════════════════════════════════════════════════
# ГЛАВНЫЙ ЦИКЛ
# ═══════════════════════════════════════════════════════

main_loop() {
    RESTART_COUNT=0
    COOLDOWN=0
    COOLDOWN_UNTIL=0
    LAST_IPTABLES_CHECK=0
    LAST_LEAK_CHECK=0
    LAST_IFACE_LOAD=0
    WAN_WAS_DOWN=0
    RESTART_FAILS=0
    LAST_RESTART_OK=0
    LAST_DOWN_REASON=""
    IFACE_RELOAD_INTERVAL=300

    # Дедупликация событий vpn_down/recovery_attempt (см. note_down, log_recovery_attempt)
    LAST_VPN_DOWN_REASON=""
    LAST_VPN_DOWN_TIME=0
    LAST_RECOVERY_REASON=""
    LAST_RECOVERY_TIME=0
    LAST_FIREWALL_RESTORED_TIME=0  # дедупликация firewall_restored — не чаще раз в 5 мин
    RECOVERY_ATTEMPT_DEDUPED=0     # маркер для do_recovery: 1 = log_recovery_attempt был дедуплицирован

    # DAEMON_JUST_STARTED: флаг "только что стартовали" — когда vpn_ok=1 впервые,
    # это означает что VPN поднялся сам (не после failover) → пишем auto_start.
    DAEMON_JUST_STARTED=1

    # BOOTED_RECENTLY: различает cold boot (reboot сервера) от live restart (update.sh).
    # Читаем uptime системы — если < 90с, значит daemon стартует сразу после boot.
    #
    # Зачем это нужно для auto_start логики:
    #   • Cold boot: ACTIVATED_BY в /var/www/minevpn-state остался от прошлой сессии
    #                (юзер активировал конфиг ДО reboot → state=manual). После reboot это
    #                уже НЕ свежее manual_activate, а просто восстановление VPN — пишем
    #                auto_start чтобы юзер видел "Автоматически запущен X" в журнале.
    #   • Live restart: daemon перезапустили (update.sh, systemctl restart) — ACTIVATED_BY
    #                может быть свежим (юзер только что клацнул "Подключить" → manual_activate
    #                уже записан PHPом). НЕ пишем auto_start чтобы не было дубль-события.
    #
    # Threshold 90с: cold boot до старта HC daemon обычно занимает 10-30с (network-online.target
    # + systemd dependencies). Live restart почти всегда происходит когда uptime >> минут
    # (update.sh запускается через cron 04:00 — uptime минимум часы).
    SYSTEM_UPTIME=$(awk '{print int($1)}' /proc/uptime 2>/dev/null || echo 999)
    if [ "$SYSTEM_UPTIME" -lt 90 ]; then
        BOOTED_RECENTLY=1
        log "INFO" "Cold boot detected (uptime=${SYSTEM_UPTIME}с) — auto_start будет записан независимо от ACTIVATED_BY"
    else
        BOOTED_RECENTLY=0
    fi

    # WAN_STATE: состояние интернета провайдера. Когда "down" — HC не трогает VPN,
    # ждёт возврата интернета. Различает "ISP лёг" от "VPN сломался".
    WAN_STATE="ok"
    WAN_DOWN_SINCE=0

    load_interfaces
    LAST_IFACE_LOAD=$(date +%s)

    log "INFO" "Health Check v5 daemon запущен"

    trap 'log "INFO" "Daemon остановлен"; exit 0' TERM INT

    # ═══════════════════════════════════════════════════════
    # WARMUP PHASE — ожидание стабильности VPN после старта daemon
    # ═══════════════════════════════════════════════════════
    #
    # ПРОБЛЕМА, КОТОРУЮ РЕШАЕМ:
    # При reboot/restart daemon wg-quick@tun0 и vpn-healthcheck.service стартуют параллельно
    # (оба имеют After=network-online.target). WG handshake занимает 5-15с,
    # OVPN TLS-init до 30с — HC daemon видит failed ping в первые секунды и ложно
    # решает что VPN сломан → recovery → failover на backup конфиг.
    # Активный конфиг сам поднялся бы за 30с, но панель уже переключилась на резерв
    # и журнал выглядит как "сбой" (с reason=unknown — vpn_down не было потому
    # что vpn_ok изначально =0, note_down вызывается только при переходе 1→0).
    #
    # РЕШЕНИЕ:
    # Перед входом в main while-loop — ждём до WARMUP_TIMEOUT секунд пока
    # ping_vpn пройдёт хотя бы один раз. Если прошло — break, normal flow,
    # main_loop пишет auto_start и юзер видит "Автоматически запущен X".
    # Если истёк — реальная проблема, выставляем осмысленный LAST_DOWN_REASON
    # чтобы recovery_attempt/failover events показали внятный текст вместо "unknown".
    #
    # НЕ пропускаем warmup если:
    #   • VPN остановлен юзером (VPN_STATE=stopped) — нечего ждать
    #   • Мониторинг выключен (vpnchecker=false) — daemon не должен ничего делать
    #   • Нет активного конфига (get_vpn_type пусто) — нечего мониторить
    local warmup_start warmup_elapsed warmup_done
    warmup_start=$(date +%s)
    warmup_elapsed=0
    warmup_done=0
    log "INFO" "Warmup phase: ожидаем стабильности VPN (до ${WARMUP_TIMEOUT}с после старта)..."

    while [ "$warmup_elapsed" -lt "$WARMUP_TIMEOUT" ]; do
        read_vpn_state

        # Юзер остановил VPN перед reboot — выходим в main loop который будет sleep'ить в stopped state
        if [ "$VPN_STATE" = "stopped" ]; then
            log "INFO" "VPN остановлен — warmup прерван"
            warmup_done=1; break
        fi

        # Мониторинг выключен — daemon не вмешивается, прерываем warmup
        if ! check_settings; then
            log "INFO" "Мониторинг VPN отключён — warmup прерван"
            warmup_done=1; break
        fi

        # Активный конфиг отсутствует (нет wg/ovpn .conf) — нечего ждать
        if [ -z "$(get_vpn_type)" ]; then
            log "WARN" "Активный конфиг отсутствует — warmup прерван"
            warmup_done=1; break
        fi

        # VPN стабилизировался — выходим в normal flow.
        # НЕ пишем auto_start здесь — он запишется на первой итерации main_loop
        # (vpn_ok=0 → ping success → "VPN стабилен" → DAEMON_JUST_STARTED=1 → log_event auto_start).
        if check_iface && check_ip && ping_vpn; then
            log "OK" "VPN стабилен после ${warmup_elapsed}с warmup"
            warmup_done=1; break
        fi

        sleep 3
        warmup_elapsed=$(( $(date +%s) - warmup_start ))
    done

    if [ "$warmup_done" != "1" ]; then
        # Warmup истёк — VPN реально не установился за WARMUP_TIMEOUT секунд.
        # main_loop ниже увидит ping_vpn fail → recovery → (возможно) failover.
        # Выставляем LAST_DOWN_REASON чтобы recovery_attempt и failover events в журнале
        # показали внятный текст вместо "Причина: unknown".
        log "WARN" "VPN не установился за ${WARMUP_TIMEOUT}с warmup — переходим в recovery"
        LAST_DOWN_REASON="VPN не установился за ${WARMUP_TIMEOUT}с после старта сервера"
    fi

    local vpn_ok=0
    local now_iface

    while true; do
        read_vpn_state

        if [ "$VPN_STATE" = "stopped" ]; then
            vpn_ok=0
            sleep 5; continue
        fi

        if [ "$VPN_STATE" = "restarting" ]; then
            sleep 2; continue
        fi

        if ! check_settings; then
            sleep 30; continue
        fi

        if [ -z "$(get_vpn_type)" ]; then
            if [ "$VPN_STATE" = "running" ] || [ "$VPN_STATE" = "recovering" ]; then
                check_autoup && restart_vpn
            fi
            sleep 5; continue
        fi

        if [ "$WAN_WAS_DOWN" -eq 1 ] && check_wan_has_ip; then
            log "INFO" "WAN вернулся — сбрасываю cooldown"
            COOLDOWN=0; COOLDOWN_UNTIL=0; RESTART_FAILS=0; WAN_WAS_DOWN=0
        fi

        # Если ISP был недоступен — проверяем возврат через WAN ping
        if [ "$WAN_STATE" = "down" ]; then
            if ping_wan; then
                local wan_now wan_duration
                wan_now=$(date +%s)
                wan_duration=$((wan_now - WAN_DOWN_SINCE))
                log "OK" "Интернет провайдера восстановлен (был недоступен ${wan_duration}с)"
                log_event isp_restored "$wan_duration"
                WAN_STATE="ok"
                COOLDOWN=0; COOLDOWN_UNTIL=0; RESTART_FAILS=0

                # Проактивный перезапуск VPN после WAN-флуктуации.
                # ПРИЧИНА: carrier-down на WAN вызывает network events которые удаляют
                # policy routing rules (fwmark), установленные wg-quick через PostUp.
                # Когда WAN возвращается — дефолтный route восстанавливается, но
                # fwmark-правила для WireGuard НЕ. В результате следующий цикл HC
                # обнаруживает "WG fwmark rule lost" → пишет vpn_down → делает failover
                # хотя конфиг на самом деле рабочий. systemctl restart перевыполняет
                # PostUp и восстанавливает все правила без лишних событий в UI.
                local vpn_type
                vpn_type=$(get_vpn_type)
                if [ -n "$vpn_type" ] && [ "$VPN_STATE" != "stopped" ] && [ "$VPN_STATE" != "restarting" ]; then
                    log "INFO" "Проактивный перезапуск VPN для восстановления маршрутов..."
                    if [ "$vpn_type" = "wg" ]; then
                        systemctl restart "wg-quick@${INTERFACE}" 2>/dev/null
                    else
                        systemctl restart "openvpn@${INTERFACE}" 2>/dev/null
                    fi
                    # Poll до 10с пока VPN поднимется — синхронно, потому что дальше цикл
                    # сразу пойдёт проверять ping_vpn, а он ещё не готов.
                    local i=0
                    local recovered=0
                    while [ $i -lt 10 ]; do
                        sleep 1; i=$((i + 1))
                        if check_iface && check_ip && ping_vpn; then
                            log "OK" "VPN восстановлен за ${i}с после возврата WAN"
                            recovered=1
                            vpn_ok=1
                            LAST_DOWN_REASON=""
                            break
                        fi
                    done
                    if [ "$recovered" -eq 1 ]; then
                        # Пишем событие "VPN поднят" чтобы юзер видел хронологию:
                        # isp_down → isp_restored → auto_start (VPN вернулся на том же конфиге).
                        log_event auto_start "${ACTIVE_ID:-}"
                        # Сбрасываем дедуп: новые падения будут записаны как новые события
                        reset_event_dedup
                    else
                        # Proactive restart не помог — основной конфиг реально проблемный.
                        # Следующий цикл main_loop увидит ping_vpn fail + ping_wan ok → пойдёт в
                        # recovery → failover. Ставим явную причину чтобы событие recovery_attempt
                        # в stats.log показало осмысленный текст вместо устаревшего reason.
                        log "WARN" "VPN не поднялся за 10с после возврата WAN — идём в recovery/failover"
                        LAST_DOWN_REASON="VPN недоступен после возврата WAN"
                        vpn_ok=0
                    fi
                else
                    # VPN stopped/restarting — не трогаем, просто пауза
                    sleep 3
                fi
                continue
            else
                # Всё ещё нет интернета — ничего не делаем, ждём
                sleep "$PING_INTERVAL"
                continue
            fi
        fi

        if ! check_iface; then
            [ "$vpn_ok" -eq 1 ] && { log "WARN" "$INTERFACE пропал"; note_down "Интерфейс пропал"; vpn_ok=0; }
            check_autoup && restart_vpn
            sleep "$PING_INTERVAL"; continue
        fi

        if ! check_ip; then
            [ "$vpn_ok" -eq 1 ] && { log "WARN" "$INTERFACE без IP"; note_down "Нет IP"; vpn_ok=0; }
            check_autoup && restart_vpn
            sleep "$PING_INTERVAL"; continue
        fi

        if ! ping_vpn; then
            sleep 1
            if ! ping_vpn; then
                # Перед тем как обвинять VPN — проверяем жив ли сам интернет провайдера.
                # Если WAN тоже не отвечает → ISP лёг, VPN не виноват, ждём.
                if ! ping_wan; then
                    log "WARN" "Интернет провайдера недоступен — VPN не трогаем, ждём возврата"
                    log_event isp_down
                    WAN_STATE="down"
                    WAN_DOWN_SINCE=$(date +%s)
                    vpn_ok=0
                    sleep "$PING_INTERVAL"; continue
                fi
                # WAN жив → проблема реально в VPN
                [ "$vpn_ok" -eq 1 ] && { log "WARN" "Нет связи через $INTERFACE"; note_down "Нет связи"; vpn_ok=0; }
                check_autoup && restart_vpn
                sleep "$PING_INTERVAL"; continue
            fi
        fi

        if ! check_vpn_routing; then
            vpn_ok=0
            check_autoup && restart_vpn
            sleep "$PING_INTERVAL"; continue
        fi

        # VPN стабилен
        if [ "$vpn_ok" -eq 0 ]; then
            vpn_ok=1; RESTART_FAILS=0
            if [ "$VPN_STATE" != "running" ]; then
                VPN_STATE="running"; save_vpn_state
            fi
            # Если это первый раз после запуска daemon — возможно писать auto_start.
            # Логика разделяется по BOOTED_RECENTLY (cold boot vs live restart):
            #
            #   • Cold boot (BOOTED_RECENTLY=1) — ВСЕГДА пишем auto_start. ACTIVATED_BY в state
            #     остался с прошлой сессии (юзер активировал ДО reboot), это уже не свежее
            #     событие. После reboot юзеру нужна явная отметка "VPN восстановлен на X"
            #     иначе журнал выглядит так: "Перезагрузка" → тишина (как видит юзер сейчас).
            #
            #   • Live restart (BOOTED_RECENTLY=0) — поважаем ACTIVATED_BY. Если manual/failover —
            #     PHP/do_recovery уже записали свою событию, не дублируем. Если auto/empty —
            #     daemon стартует сам (например после update.sh) и должен отметить что VPN ОК.
            if [ "$DAEMON_JUST_STARTED" = "1" ]; then
                if [ "$BOOTED_RECENTLY" = "1" ]; then
                    log_event auto_start "${ACTIVE_ID:-}"
                elif [ "$ACTIVATED_BY" != "manual" ] && [ "$ACTIVATED_BY" != "failover" ]; then
                    log_event auto_start "${ACTIVE_ID:-}"
                fi
                DAEMON_JUST_STARTED=0
            fi
            LAST_DOWN_REASON=""
            reset_event_dedup
            log "OK" "VPN стабилен"
        fi

        now_iface=$(date +%s)
        if [ $((now_iface - LAST_IFACE_LOAD)) -ge "$IFACE_RELOAD_INTERVAL" ]; then
            load_interfaces; LAST_IFACE_LOAD=$now_iface
        fi
        check_iptables
        if ! check_leak; then
            check_autoup && restart_vpn
            vpn_ok=0; sleep "$PING_INTERVAL"; continue
        fi

        sleep "$PING_INTERVAL"
    done
}

main_loop
