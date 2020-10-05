

#Update all apt packages
sudo apt -y update && sudo apt -y full-upgrade && sudo apt -y autoremove && sudo apt -y clean && echo "SUCCESS"


#Update youtube-dl
sudo youtube-dl --update && echo "SUCCESS"


#Update everything in the ~/jkbox dir
# wget https://kylegabler.com/assets/jkbox/jkbox.zip -O /tmp/jkbox.zip && unzip -o /tmp/jkbox.zip -d /tmp/jkbox && rsync /tmp/jkbox/ /home/pi/jkbox/ -vrc --exclude=/tmp --exclude=/tracks --delete && rm -rf /tmp/jkbox* && echo "SUCCESS"

