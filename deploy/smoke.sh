#!/usr/bin/env bash
# deploy/smoke.sh — a read-only check of the live site from anywhere with bash, curl and node (docs/handover.md §6):
#
#   bash deploy/smoke.sh
#
# GET requests and CORS preflights only: nothing is created, sent or changed. Checks every host and its certificate,
# that the wallet stays closed, the public API and the website's pages on live content, that signed-in areas refuse
# anonymous calls, and CORS. Exit code 0 when everything passes.
set -uo pipefail

APEX=https://bhabaghure.com.bd
API=https://api.bhabaghure.com.bd
DIR=$(mktemp -d)
PASS=0
FAIL=0
NOTES=()

check() { # name, expected (regex), actual
  if [[ $3 =~ ^($2)$ ]]; then
    PASS=$((PASS + 1)); printf 'PASS  %-62s %s\n' "$1" "$3"
  else
    FAIL=$((FAIL + 1)); printf 'FAIL  %-62s got %s, expected %s\n' "$1" "$3" "$2"
  fi
}
note() { NOTES+=("$1"); }

# status of a GET, saving body and headers under a key
get() { # key url [extra curl args]
  local key=$1 url=$2
  shift 2
  curl -s -o "$DIR/$key.body" -D "$DIR/$key.head" -w '%{http_code}' --max-time 30 "$@" "$url"
}
header() { grep -i "^$2:" "$DIR/$1.head" | tail -1 | cut -d' ' -f2- | tr -d '\r'; }
body_has() { grep -q -- "$2" "$DIR/$1.body" && echo yes || echo no; }
json() { node -e "const d=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8'));const f=new Function('d','return '+process.argv[2]);console.log(f(d))" "$DIR/$1.body" "$2"; }

echo "── Hosts and TLS"
check "apex home (bn)" 200 "$(get home "$APEX/")"
check "apex home is Bangla" yes "$(body_has home 'ভবঘুরে')"
check "apex home (en)" 200 "$(get home_en "$APEX/en")"
check "/bn redirects to the unprefixed URL" 308 "$(get bn "$APEX/bn")"
check "www redirects to apex" '301|308' "$(get www https://www.bhabaghure.com.bd/)"
check "www target" "$APEX/" "$(header www location)"
check "http → https" '301|308' "$(get http http://bhabaghure.com.bd/)"
check "customer portal" 200 "$(get portal https://customer.bhabaghure.com.bd/)"
check "admin app" 200 "$(get admin https://admin.bhabaghure.com.bd/)"
asset=$(grep -o '/assets/[^"]*\.js' "$DIR/admin.body" | head -1)
check "admin script asset" 200 "$(get admin_js "https://admin.bhabaghure.com.bd$asset")"
check "admin never framed" DENY "$(header admin x-frame-options)"
check "API /up" 200 "$(get up "$API/up")"
check "TLS verified (apex)" 0 "$(curl -s -o /dev/null -w '%{ssl_verify_result}' "$APEX/")"
check "TLS verified (api)" 0 "$(curl -s -o /dev/null -w '%{ssl_verify_result}' "$API/up")"
check "TLS verified (admin)" 0 "$(curl -s -o /dev/null -w '%{ssl_verify_result}' https://admin.bhabaghure.com.bd/)"

echo "── Wallet stays closed"
check "wallet from outside the allow-list" '403|401' "$(get wallet https://wallet.bhabaghure.com.bd/)"
check "wallet API from outside the allow-list" '403|401' "$(get wallet_api https://wallet.bhabaghure.com.bd/api/v1/wallet/auth/me)"
check "wallet API absent on the API host" 404 "$(get wallet_on_api "$API/api/v1/wallet/auth/me" -H 'X-Wallet-Request: 1')"

echo "── Public API (live CMS data)"
for path in settings destinations packages departures posts team reviews gallery visas creator partners pricing; do
  check "GET /public/$path" 200 "$(get "p_$path" "$API/api/v1/public/$path" -H 'Accept: application/json')"
done
check "destinations listed" '[1-9][0-9]*' "$(json p_destinations "(d.data||d).length")"
reels=$(json p_gallery "(d.data||[]).filter(i=>i.kind==='reel').length")
packages=$(json p_packages "(d.data||d).length")
check "published packages listed" '[1-9][0-9]*' "$packages"
slug=$(json p_packages "(d.data||d)[0].slug")
check "package detail /public/packages/{slug}" 200 "$(get p_package "$API/api/v1/public/packages/$slug" -H 'Accept: application/json')"
post=$(json p_posts "(d.data.posts||[])[0]?.slug||''")
[[ -n $post ]] && check "post detail /public/posts/{slug}" 200 "$(get p_post "$API/api/v1/public/posts/$post" -H 'Accept: application/json')"
check "unknown booking reference" 404 "$(get p_booking "$API/api/v1/public/bookings/BH-0000-999" -H 'Accept: application/json' -H 'X-Booking-Token: nope')"
check "unknown invoice share token" 404 "$(get p_invoice "$API/api/v1/public/invoices/not-a-token" -H 'Accept: application/json')"
check "API hides X-Powered-By" '' "$(header p_settings x-powered-by)"

echo "── Signed-in areas refuse anonymous calls"
check "admin bookings without a token" 401 "$(get a_bookings "$API/api/v1/admin/bookings" -H 'Accept: application/json')"
check "admin payroll without a token" 401 "$(get a_payroll "$API/api/v1/admin/payroll" -H 'Accept: application/json')"
check "portal trips without a token" 401 "$(get c_trips "$API/api/v1/portal/trips" -H 'Accept: application/json')"
check "my commission without a token" 401 "$(get a_comm "$API/api/v1/admin/profile/commission" -H 'Accept: application/json')"
check "hotel requests without a token" 401 "$(get a_hotel "$API/api/v1/admin/hotel-inquiries" -H 'Accept: application/json')"
check "brochure download without signing in" 401 "$(get c_brochure "$API/api/v1/portal/downloads/packages/any" -H 'Accept: application/json')"
check "downloads screen without a token" 401 "$(get a_downloads "$API/api/v1/admin/downloads" -H 'Accept: application/json')"
check "chart of accounts without a token" 401 "$(get a_accounts "$API/api/v1/admin/accounts" -H 'Accept: application/json')"
check "journal entries without a token" 401 "$(get a_journal "$API/api/v1/admin/journal-entries" -H 'Accept: application/json')"

echo "── CORS"
cors() { curl -s -o /dev/null -D - -X OPTIONS --max-time 20 -H "Origin: $1" -H 'Access-Control-Request-Method: POST' "$API/api/v1/public/inquiries" | grep -i '^access-control-allow-origin:' | cut -d' ' -f2- | tr -d '\r'; }
check "CORS allows the website" "$APEX" "$(cors "$APEX")"
check "CORS allows the admin" https://admin.bhabaghure.com.bd "$(cors https://admin.bhabaghure.com.bd)"
check "CORS refuses another origin" '' "$(cors https://evil.example)"

echo "── Website pages"
check "package page (bn)" 200 "$(get w_pkg "$APEX/packages/$slug")"
check "package page (en)" 200 "$(get w_pkg_en "$APEX/en/packages/$slug")"
[[ -n $post ]] && check "blog post page" 200 "$(get w_post "$APEX/blog/$post")"
visa=$(json p_visas "((d.data||[])[0]||{}).slug||''")
if [[ -n $visa ]]; then
  check "visa page /visa/{slug}" 200 "$(get w_visa "$APEX/visa/$visa")"
  check "home has the Visa section" yes "$(body_has home 'id="visa"')"
else
  note "No visa services published in Admin → Visa services; the Visa section and tab are hidden."
fi
partners=$(json p_partners "(d.data||[]).length")
if [[ $partners -gt 0 ]]; then
  check "home shows the $partners airline partners" yes "$(body_has home 'id="partners"')"
else
  note "No airlines published in Admin → Airline partners; the band is hidden."
fi
check "footer shows the payment methods" yes "$(body_has home 'pay-with-sslcommerz')"
host=$(json p_creator "d.data&&d.data.profile?'yes':''")
if [[ -n $host ]]; then
  check "home has the Travel host section" yes "$(body_has home 'id="travel-host"')"
else
  note "No profile in Admin → Travel host; the section is hidden."
fi
for page in terms privacy refund-policy; do
  check "/$page (bn)" 200 "$(get "w_$page" "$APEX/$page")"
  check "/en/$page" 200 "$(get "w_${page}_en" "$APEX/en/$page")"
done
check "sitemap.xml" 200 "$(get sitemap "$APEX/sitemap.xml")"
check "sitemap lists the package" yes "$(body_has sitemap "$slug")"
check "robots.txt" 200 "$(get robots "$APEX/robots.txt")"
check "unknown page" 404 "$(get w_404 "$APEX/no-such-page-$RANDOM")"
check "unknown page is the site's own 404" yes "$(body_has w_404 'পাতাটি পাওয়া যায়নি')"
check "portal is noindex or private" yes "$( (grep -qi 'noindex' "$DIR/portal.body" || grep -qi 'x-robots-tag: noindex' "$DIR/portal.head") && echo yes || echo no)"
icon=$(grep -o '<link rel="icon" href="[^"]*"' "$DIR/home.body" | head -1 | sed 's/.*href="//; s/"$//')
check "site icon linked" yes "$([[ -n $icon ]] && echo yes || echo no)"
[[ -n $icon ]] && check "site icon loads" 200 "$(get icon "$APEX${icon%%\?*}")"
if [[ $reels -gt 0 ]]; then
  check "home plays the $reels published reels in Facebook's player" "$reels" "$(grep -o '<iframe[^>]*facebook.com/plugins/video.php' "$DIR/home.body" | wc -l | tr -d ' ')"
else
  note "No reels published in Admin → Gallery; the home page has no reel players."
fi
# Sections the CMS left empty render nothing; no menu link may point at them.
for section in departures gallery; do
  if ! grep -q "id=\"$section\"" "$DIR/home.body"; then
    check "no link to the empty #$section section" 0 "$(grep -c "href=\"[^\"]*#$section\"" "$DIR/home.body")"
  fi
done

echo "── Security headers"
for key in home portal p_settings; do
  check "$key: X-Frame-Options" DENY "$(header "$key" x-frame-options)"
  check "$key: X-Content-Type-Options" nosniff "$(header "$key" x-content-type-options)"
  check "$key: Referrer-Policy" 'strict-origin-when-cross-origin|no-referrer' "$(header "$key" referrer-policy)"
done

echo "── Headers worth knowing"
hsts=$(header home strict-transport-security)
[[ -n $hsts ]] && note "HSTS on the apex: $hsts" || note "No Strict-Transport-Security header on the apex (Cloudflare's HSTS setting is off)."
note "Apex served via: $(header home server) / cf-cache-status $(header home cf-cache-status)"
note "Home page TTFB $(curl -s -o /dev/null -w '%{time_starttransfer}' "$APEX/")s; API settings TTFB $(curl -s -o /dev/null -w '%{time_starttransfer}' "$API/api/v1/public/settings")s"

echo
printf 'Result: %s passed, %s failed\n' "$PASS" "$FAIL"
for n in "${NOTES[@]}"; do printf 'NOTE  %s\n' "$n"; done
rm -rf "$DIR"
[[ $FAIL -eq 0 ]]
