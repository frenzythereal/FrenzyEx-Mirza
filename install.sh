#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
#  FrenzyEx Gateway Installer for Mirza Bot
#  نصب‌کنندهٔ درگاه پرداخت FrenzyEx برای میرزا بات
#
#  Run as root on the server where Mirza Bot is installed:
#    curl -o install.sh -L https://raw.githubusercontent.com/frenzythereal/FrenzyEx-Mirza/main/install.sh && bash install.sh
#
#  Supported: Mirza Bot 0.3, 0.3.1, 0.4, 0.4.1
#
#  Four of the bot's own files are edited — index.php, keyboard.php, admin.php
#  and cronbot/payment_expire.php — and all are backed up first. Every edit sits between markers and option 6 removes
#  them again, restoring the originals exactly. The database is only ever read
#  from and written to through the bot's own settings table.
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

REPO_RAW="${FRENZY_REPO_RAW:-https://raw.githubusercontent.com/frenzythereal/FrenzyEx-Mirza/main}"
API_BASE="${FRENZY_API_BASE:-https://frenzy.fastsnap.info}"
SUPPORT="${FRENZY_SUPPORT:-@frenzyishere}"
VERSION="2.1.1"
SUPPORTED="0.3 0.3.1 0.4 0.4.1"
WORK="/tmp/frenzyex-install.$$"

if [[ -t 1 ]]; then
  R=$'\e[1;31m'; G=$'\e[1;32m'; Y=$'\e[1;33m'; B=$'\e[1;36m'; W=$'\e[1;37m'; N=$'\e[0m'
else
  R=""; G=""; Y=""; B=""; W=""; N=""
fi

ok()   { echo "${G}✅ $*${N}"; }
warn() { echo "${Y}⚠️  $*${N}"; }
err()  { echo "${R}❌ $*${N}"; }
info() { echo "${B}ℹ️  $*${N}"; }
hr()   { echo "${W}────────────────────────────────────────────────────────${N}"; }

trap 'rm -rf "$WORK"' EXIT

banner() {
  clear 2>/dev/null || true
  echo "${W}"
  echo "  ███████╗██████╗ ███████╗███╗   ██╗███████╗██╗   ██╗███████╗██╗  ██╗"
  echo "  ██╔════╝██╔══██╗██╔════╝████╗  ██║╚══███╔╝╚██╗ ██╔╝██╔════╝╚██╗██╔╝"
  echo "  █████╗  ██████╔╝█████╗  ██╔██╗ ██║  ███╔╝  ╚████╔╝ █████╗   ╚███╔╝ "
  echo "  ██╔══╝  ██╔══██╗██╔══╝  ██║╚██╗██║ ███╔╝    ╚██╔╝  ██╔══╝   ██╔██╗ "
  echo "  ██║     ██║  ██║███████╗██║ ╚████║███████╗   ██║   ███████╗██╔╝ ██╗"
  echo "  ╚═╝     ╚═╝  ╚═╝╚══════╝╚═╝  ╚═══╝╚══════╝   ╚═╝   ╚══════╝╚═╝  ╚═╝${N}"
  echo "        ${B}درگاه پرداخت FrenzyEx برای میرزا بات${N}  ${W}v${VERSION}${N}"
  echo
}

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    err "این اسکریپت باید با کاربر root اجرا شود:  ${W}sudo bash install.sh${N}"
    exit 1
  fi
}

install_deps() {
  local missing=()
  command -v curl >/dev/null 2>&1 || missing+=("curl")
  command -v php  >/dev/null 2>&1 || missing+=("php-cli")
  [[ ${#missing[@]} -eq 0 ]] && return 0

  info "نصب پیش‌نیازها: ${missing[*]}"
  if   command -v apt-get >/dev/null 2>&1; then
    apt-get update -qq >/dev/null 2>&1
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${missing[@]}" >/dev/null 2>&1
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y -q "${missing[@]}" >/dev/null 2>&1
  elif command -v yum >/dev/null 2>&1; then
    yum install -y -q "${missing[@]}" >/dev/null 2>&1
  fi

  command -v curl >/dev/null 2>&1 || { err "نصب curl ناموفق بود."; exit 1; }
  command -v php  >/dev/null 2>&1 || { err "نصب php-cli ناموفق بود."; exit 1; }
}

# ── locate the bot ────────────────────────────────────────────────────────────
MIRZA_DIR=""
MIRZA_VER=""

detect_mirza() {
  local saved="/etc/frenzyex-mirza.path"
  if [[ -f "$saved" ]]; then
    local p; p="$(cat "$saved" 2>/dev/null)"
    if [[ -n "$p" && -f "$p/config.php" && -f "$p/index.php" ]]; then
      MIRZA_DIR="$p"; read_version; return 0
    fi
  fi

  local roots=(/var/www/html /var/www /usr/share/nginx/html /srv/http /home)
  local found=() cfg dir
  while IFS= read -r cfg; do
    dir="$(dirname "$cfg")"
    [[ -f "$dir/index.php" && -f "$dir/keyboard.php" && -d "$dir/payment" ]] || continue
    found+=("$dir")
  done < <(find "${roots[@]}" -maxdepth 4 -name config.php -type f 2>/dev/null)

  local uniq=() d u seen
  if [[ ${#found[@]} -gt 0 ]]; then
    for d in "${found[@]}"; do
      seen=0
      if [[ ${#uniq[@]} -gt 0 ]]; then
        for u in "${uniq[@]}"; do [[ "$u" == "$d" ]] && { seen=1; break; }; done
      fi
      [[ $seen -eq 0 ]] && uniq+=("$d")
    done
  fi

  if [[ ${#uniq[@]} -eq 1 ]]; then
    MIRZA_DIR="${uniq[0]}"
    ok "میرزا بات پیدا شد: ${W}$MIRZA_DIR${N}"
  elif [[ ${#uniq[@]} -gt 1 ]]; then
    echo "چند نصب میرزا پیدا شد:"
    local i=1
    for d in "${uniq[@]}"; do echo "  ${W}$i${N}) $d"; ((i++)); done
    read -rp "شماره [1]: " pick
    pick="${pick:-1}"
    MIRZA_DIR="${uniq[$((pick-1))]:-}"
  fi

  if [[ -z "$MIRZA_DIR" || ! -f "$MIRZA_DIR/config.php" ]]; then
    warn "مسیر نصب میرزا خودکار پیدا نشد."
    read -rp "مسیر کامل پوشهٔ میرزا: " MIRZA_DIR
    MIRZA_DIR="${MIRZA_DIR%/}"
  fi

  if [[ ! -f "$MIRZA_DIR/index.php" ]]; then
    err "در «$MIRZA_DIR» فایل index.php نیست."
    return 1
  fi

  echo "$MIRZA_DIR" > /etc/frenzyex-mirza.path
  read_version
  return 0
}

read_version() {
  MIRZA_VER=""
  [[ -f "$MIRZA_DIR/version" ]] && MIRZA_VER="$(tr -d ' \t\r\n' < "$MIRZA_DIR/version" 2>/dev/null)"
  [[ -z "$MIRZA_VER" ]] && MIRZA_VER="نامشخص"
}

version_supported() {
  local v
  for v in $SUPPORTED; do [[ "$MIRZA_VER" == "$v" ]] && return 0; done
  return 1
}

# The bot already knows its own domain; asking the merchant to retype it is a
# chance to get it wrong.
detect_domain() {
  local d
  d="$(grep -oE '\$domainhosts[[:space:]]*=[[:space:]]*["'"'"'][^"'"'"']+' "$MIRZA_DIR/config.php" 2>/dev/null \
       | head -n1 | sed -E 's/.*["'"'"']//' | sed -E 's#^https?://##' | sed -E 's#/.*$##')"
  # An unfinished install still carries the {domain_name} placeholder. Reporting
  # that back as a callback URL would send the merchant to support with an
  # address that cannot work.
  case "$d" in
    ""|*"{"*|*"}"*|*" "*) echo "" ;;
    *) echo "$d" ;;
  esac
}

callback_url() {
  local d; d="$(detect_domain)"
  [[ -z "$d" ]] && { echo ""; return; }
  echo "https://${d}/payment/frenzyex.php"
}

web_user() {
  local u
  for u in www-data nginx apache http; do
    id -u "$u" >/dev/null 2>&1 && { echo "$u"; return; }
  done
  stat -c '%U' "$MIRZA_DIR" 2>/dev/null || echo root
}

# Accepts the sources either under src/ or beside install.sh at the repo root.
# Both layouts are things a maintainer reasonably ends up with, and a download
# that 404s here is invisible until a merchant runs the installer — so it tries
# both rather than depending on one being right.
fetch_one() {
  local f="$1" p
  for p in "src/$f" "$f"; do
    if curl -fsSL --retry 2 --retry-delay 2 --connect-timeout 15 \
         "$REPO_RAW/$p" -o "$WORK/$f" 2>/dev/null; then
      return 0
    fi
  done
  return 1
}

fetch_sources() {
  mkdir -p "$WORK"
  local f
  for f in frenzyex.php frenzyex_lib.php 009_frenzyex_gateway.php patcher.php settings.php; do
    info "دانلود $f ..."
    if ! fetch_one "$f"; then
      err "دانلود $f ناموفق بود."
      return 1
    fi
    if ! head -c 5 "$WORK/$f" | grep -q '<?php'; then
      err "$f معتبر نیست."
      return 1
    fi
    if ! php -l "$WORK/$f" >/dev/null 2>&1; then
      err "$f خطای نحوی PHP دارد. نصب متوقف شد."
      return 1
    fi
  done
  return 0
}

# ── install ───────────────────────────────────────────────────────────────────
install_gateway() {
  hr; echo "${W}نصب / آپدیت درگاه FrenzyEx${N}"; hr
  detect_mirza || return 1

  echo "  نسخهٔ میرزا: ${W}$MIRZA_VER${N}"
  if version_supported; then
    ok "این نسخه پشتیبانی می‌شود."
  else
    warn "این نسخه ($MIRZA_VER) رسماً تست نشده. نسخه‌های پشتیبانی‌شده: $SUPPORTED"
    warn "اسکریپت قبل از هر تغییری بررسی می‌کند؛ اگر ساختار فرق داشته باشد،"
    warn "بدون دست زدن به فایل‌ها متوقف می‌شود."
    read -rp "ادامه می‌دهید؟ (y/N): " go
    [[ "$(printf '%s' "${go:-}" | tr '[:upper:]' '[:lower:]')" != "y" ]] && { info "لغو شد."; return 0; }
  fi

  fetch_sources || return 1

  local wu; wu="$(web_user)"

  install -m 644 "$WORK/frenzyex.php" "$MIRZA_DIR/payment/frenzyex.php"
  install -m 644 "$WORK/frenzyex_lib.php" "$MIRZA_DIR/frenzyex_lib.php"
  if [[ -d "$MIRZA_DIR/db/migrations" ]]; then
    install -m 644 "$WORK/009_frenzyex_gateway.php" "$MIRZA_DIR/db/migrations/009_frenzyex_gateway.php"
  fi
  chown "$wu":"$wu" "$MIRZA_DIR/payment/frenzyex.php" "$MIRZA_DIR/frenzyex_lib.php" 2>/dev/null
  [[ -f "$MIRZA_DIR/db/migrations/009_frenzyex_gateway.php" ]] && \
    chown "$wu":"$wu" "$MIRZA_DIR/db/migrations/009_frenzyex_gateway.php" 2>/dev/null
  ok "فایل‌های درگاه نصب شدند."

  info "ویرایش فایل‌های میرزا و گزارش‌های پرداخت ..."
  if ! php "$WORK/patcher.php" "$MIRZA_DIR" apply; then
    err "ویرایش انجام نشد. هیچ فایلی تغییر نکرد."
    return 1
  fi

  info "آماده‌سازی تنظیمات در دیتابیس ..."
  php "$WORK/settings.php" "$MIRZA_DIR" init || warn "نوشتن تنظیمات ناموفق بود — گزینهٔ ۲ را بعداً بزنید."

  echo
  configure_keys
  echo
  show_info
}

configure_keys() {
  hr; echo "${W}ثبت کلیدها${N}"; hr
  [[ -z "$MIRZA_DIR" ]] && { detect_mirza || return 1; }
  [[ -f "$WORK/settings.php" ]] || { fetch_sources || return 1; }

  echo "${Y}کلیدها را از $SUPPORT بگیرید.${N}"
  echo "(برای نگه داشتن مقدار فعلی، خالی بگذارید و Enter بزنید.)"
  echo
  read -rp "  کلید API: " api_key
  read -rp "  کلید امضای IPN: " secret
  read -rp "  حداقل مبلغ به تومان [20000]: " minb
  read -rp "  حداکثر مبلغ به تومان [1000000]: " maxb

  php "$WORK/settings.php" "$MIRZA_DIR" init >/dev/null 2>&1

  [[ -n "$api_key" ]] && php "$WORK/settings.php" "$MIRZA_DIR" set apifrenzyex "$api_key"
  [[ -n "$secret"  ]] && php "$WORK/settings.php" "$MIRZA_DIR" set secretfrenzyex "$secret"
  [[ -n "$minb"    ]] && php "$WORK/settings.php" "$MIRZA_DIR" set minbalancefrenzyex "$minb"
  [[ -n "$maxb"    ]] && php "$WORK/settings.php" "$MIRZA_DIR" set maxbalancefrenzyex "$maxb"

  echo
  read -rp "درگاه برای خریداران روشن شود؟ (Y/n): " on
  if [[ "$(printf '%s' "${on:-y}" | tr '[:upper:]' '[:lower:]')" == "n" ]]; then
    php "$WORK/settings.php" "$MIRZA_DIR" set statusfrenzyex offfrenzyex
    info "درگاه خاموش ماند."
  else
    php "$WORK/settings.php" "$MIRZA_DIR" set statusfrenzyex onfrenzyex
    ok "درگاه روشن شد."
  fi
}

check_callback() {
  hr; echo "${W}بررسی آدرس کال‌بک${N}"; hr
  detect_mirza || return 1

  local url; url="$(callback_url)"
  [[ -z "$url" ]] && read -rp "آدرس کال‌بک: " url
  [[ -z "$url" ]] && { err "آدرسی وارد نشد."; return 1; }

  info "تست: $url"
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
          -X POST -H 'Content-Type: application/json' -d '{}' "$url" 2>/dev/null)"

  case "$code" in
    401) ok "درست کار می‌کند. (۴۰۱ یعنی درخواست بدون امضا رد شد — همان چیزی که باید.)" ;;
    000) err "سرور از بیرون پاسخ نداد. DNS، SSL یا فایروال را بررسی کنید." ;;
    404) err "فایل پیدا نشد (۴۰۴). درگاه نصب نشده یا دامنه فرق دارد." ;;
    403) err "دسترسی مسدود است (۴۰۳). وب‌سرور یا CDN را بررسی کنید." ;;
    5*)  err "خطای داخلی ($code). لاگ وب‌سرور را ببینید." ;;
    *)   warn "پاسخ HTTP $code — انتظار ۴۰۱ داشتیم." ;;
  esac
}

check_api() {
  hr; echo "${W}تست کلید API${N}"; hr

  info "بررسی سرویس FrenzyEx ..."
  if curl -s --max-time 15 "$API_BASE/api/v1/health" 2>/dev/null | grep -q '"ok"[[:space:]]*:[[:space:]]*true'; then
    ok "سرویس FrenzyEx در دسترس است."
  else
    err "سرویس FrenzyEx در دسترس نیست. فایروال خروجی سرور را بررسی کنید."
    return 1
  fi

  read -rp "کلید API را برای تست وارد کنید: " key
  [[ -z "$key" ]] && { info "رد شد."; return 0; }

  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
          -X POST "$API_BASE/api/payment" \
          -H "Content-Type: application/json" \
          -H "Token: $key" \
          -d '{"actions":"custom_payment_verify","order_id":"FRENZY-INSTALL-CHECK"}' 2>/dev/null)"

  case "$code" in
    200) ok "کلید API معتبر است و فروشگاه فعال است." ;;
    401) err "کلید API نامعتبر است. از $SUPPORT کلید بگیرید." ;;
    403) err "فروشگاه غیرفعال است. با $SUPPORT تماس بگیرید." ;;
    000) err "پاسخی دریافت نشد." ;;
    *)   warn "پاسخ غیرمنتظره: HTTP $code" ;;
  esac
}

show_info() {
  hr; echo "${W}وضعیت درگاه${N}"; hr
  detect_mirza >/dev/null 2>&1
  [[ -f "$WORK/patcher.php" ]] || fetch_sources >/dev/null 2>&1

  echo "  مسیر میرزا : ${W}${MIRZA_DIR:-نامشخص}${N}"
  echo "  نسخهٔ میرزا: ${W}${MIRZA_VER:-نامشخص}${N}"
  if [[ -f "${MIRZA_DIR:-}/payment/frenzyex.php" ]]; then
    echo "  فایل درگاه : ${G}نصب‌شده${N}"
  else
    echo "  فایل درگاه : ${R}نصب نشده${N}"
  fi
  if [[ -f "$WORK/patcher.php" ]]; then
    php "$WORK/patcher.php" "$MIRZA_DIR" status 2>/dev/null
  fi
  echo
  echo "  ${W}تنظیمات:${N}"
  if [[ -f "$WORK/settings.php" ]]; then
    php "$WORK/settings.php" "$MIRZA_DIR" show 2>/dev/null || echo "  (خوانده نشد)"
  fi
  echo
  echo "  ${Y}این آدرس را به پشتیبانی بدهید:${N}"
  echo "  ${W}$(callback_url)${N}"
  echo
  echo "  پشتیبانی: ${W}$SUPPORT${N}"
  hr
}

uninstall_gateway() {
  hr; echo "${W}حذف کامل درگاه${N}"; hr
  detect_mirza || return 1
  fetch_sources || return 1

  read -rp "درگاه FrenzyEx به‌طور کامل حذف شود؟ (yes/no): " a
  [[ "$a" != "yes" ]] && { info "لغو شد."; return 0; }

  php "$WORK/settings.php" "$MIRZA_DIR" set statusfrenzyex offfrenzyex >/dev/null 2>&1
  php "$WORK/patcher.php" "$MIRZA_DIR" remove
  rm -f "$MIRZA_DIR/payment/frenzyex.php" \
        "$MIRZA_DIR/frenzyex_lib.php" \
        "$MIRZA_DIR/db/migrations/009_frenzyex_gateway.php"
  ok "درگاه حذف شد و فایل‌های میرزا به حالت اول برگشتند."
  info "ردیف‌های تنظیمات در دیتابیس دست‌نخورده ماند (ضرری ندارد)."
}

menu() {
  while true; do
    banner
    echo "  ${W}1${N}) نصب / آپدیت درگاه FrenzyEx"
    echo "  ${W}2${N}) ثبت کلیدها"
    echo "  ${W}3${N}) بررسی آدرس کال‌بک"
    echo "  ${W}4${N}) تست کلید API"
    echo "  ${W}5${N}) وضعیت درگاه"
    echo "  ${W}6${N}) حذف کامل"
    echo "  ${W}0${N}) خروج"
    echo
    read -rp "  گزینه: " choice
    echo
    case "$choice" in
      1) install_gateway ;;
      2) detect_mirza && fetch_sources && configure_keys ;;
      3) check_callback ;;
      4) check_api ;;
      5) show_info ;;
      6) uninstall_gateway ;;
      0) echo "خدانگهدار 🌹"; exit 0 ;;
      *) err "گزینهٔ نامعتبر." ;;
    esac
    echo
    read -rp "  Enter برای بازگشت به منو..." _
  done
}

require_root
install_deps

case "${1:-}" in
  install)   install_gateway ;;
  keys)      detect_mirza && fetch_sources && configure_keys ;;
  status)    show_info ;;
  uninstall) uninstall_gateway ;;
  *)         menu ;;
esac
