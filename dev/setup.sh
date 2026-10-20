#!/bin/bash
# Build a local Piwigo 16.4.0 with Bootstrap Darkroom, UnlistedAlbum and BatchDownloader copied from
# the live install, seed a share-linked album, and print the share token.
# Re-runnable: wipes the local install and database each time.
set -euo pipefail
cd "$(dirname "$0")"

LIVE=/home/rocky/docker-containers/jhedlund.com/htdocs/photos

if [ ! -f www/index.php ]; then
  curl -sSL -o /tmp/piwigo-16.4.0.zip https://github.com/Piwigo/Piwigo/archive/refs/tags/16.4.0.zip
  unzip -q /tmp/piwigo-16.4.0.zip -d /tmp/piwigo-src && mv /tmp/piwigo-src/Piwigo-16.4.0 www && rm -rf /tmp/piwigo-src
fi
if [ ! -d www/themes/bootstrap_darkroom ] || [ ! -d www/plugins/BatchDownloader ]; then
  ssh jhedlund.com "cd $LIVE && sudo tar czf - --exclude=.git themes/bootstrap_darkroom plugins/UnlistedAlbum plugins/BatchDownloader" | tar xzf - -C www
fi

docker compose down -v >/dev/null 2>&1 || true
rm -rf www/local/config/database.inc.php www/_data/* www/upload/*
docker compose up -d --build
docker compose exec -T web sh -c 'cd /var/www/html/photos && mkdir -p _data upload && chmod -R 777 _data upload local galleries'
until docker compose exec -T db mariadb -upiwigo -ppiwigo -e 'select 1' piwigo >/dev/null 2>&1; do sleep 1; done

U=http://localhost:8089/photos
curl -sf -o /dev/null -X POST "$U/install.php?language=en_GB" --data \
  "dbhost=db&dbuser=piwigo&dbpasswd=piwigo&dbname=piwigo&prefix=piwigo_&admin_name=admin&admin_pass1=adminpass&admin_pass2=adminpass&admin_mail=admin@example.com&install=1"

B="$U/ws.php?format=json"
J=$(mktemp)
ws() { curl -sf -c "$J" -b "$J" "$B" "$@"; }
ws --data "method=pwg.session.login&username=admin&password=adminpass" >/dev/null
TOK=$(ws --data "method=pwg.session.getStatus" | sed -E 's/.*"pwg_token":"([a-f0-9]+)".*/\1/')
ws --data "method=pwg.themes.performAction&action=activate&theme=bootstrap_darkroom&pwg_token=$TOK" >/dev/null
ws --data "method=pwg.themes.performAction&action=set_default&theme=bootstrap_darkroom&pwg_token=$TOK" >/dev/null
for plugin in UnlistedAlbum BatchDownloader gallery_pulse; do
  for action in install activate; do
    ws --data "method=pwg.plugins.performAction&action=$action&plugin=$plugin&pwg_token=$TOK" >/dev/null
  done
done

# Same theme settings as the live gallery (thumbnails open the lightbox).
docker compose exec -T db mariadb -upiwigo -ppiwigo piwigo -e \
  "REPLACE INTO piwigo_config (param, value) VALUES ('bootstrap_darkroom', '$(cat darkroom_config.json)')"
# Same Batch Downloader settings as the live gallery (guests can't zip there; the e2e enables it per test).
docker compose exec -T db mariadb -upiwigo -ppiwigo piwigo -e \
  "REPLACE INTO piwigo_config (param, value) VALUES ('batch_download', '$(cat batch_download_config.txt)')"

CAT=$(ws --data "method=pwg.categories.add&name=Test+Match" | sed -E 's/.*"id":([0-9]+).*/\1/')
SUB=$(ws --data "method=pwg.categories.add&name=Second+Half&parent=$CAT" | sed -E 's/.*"id":([0-9]+).*/\1/')
for i in 1 2 3 4 5; do
  c=$CAT; [ $i -gt 3 ] && c=$SUB
  # Photo 1 is portrait: swiping between orientations is where zoom sampling can go wrong.
  w=3000; h=2000; [ $i -eq 1 ] && w=2000 h=3000
  docker compose exec -T web php -r '$i='$i'; $w='$w'; $h='$h'; $im=imagecreatetruecolor($w,$h); imagefill($im,0,0,imagecolorallocate($im,40*$i,200-30*$i,120)); for($k=0;$k<40;$k++){imagefilledellipse($im,rand(0,$w),rand(0,$h),rand(50,400),rand(50,400),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} imagejpeg($im,"/tmp/p.jpg",85);'
  docker compose cp web:/tmp/p.jpg /tmp/gp-p$i.jpg >/dev/null
  ws -F method=pwg.images.addSimple -F category=$c -F name="Photo $i" -F image=@/tmp/gp-p$i.jpg >/dev/null
done
ws --data "method=pwg.images.emptyLounge" >/dev/null

curl -sf -o /dev/null -c "$J" -b "$J" "$U/admin.php?page=plugin-UnlistedAlbum" --data "action=make_unlisted&category_id=$CAT&pwg_token=$TOK"
TOKEN=$(docker compose exec -T db mariadb -upiwigo -ppiwigo piwigo -N -e "SELECT random_key FROM piwigo_unlisted_albums WHERE category_id=$CAT")
rm -f "$J"
echo "Admin: $U/admin.php (admin / adminpass)"
echo "Share link: $U/index.php?/v/$TOKEN"
echo "Run the e2e: python tests/e2e_test.py $TOKEN"
