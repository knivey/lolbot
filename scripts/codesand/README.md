##This script is to run code snipperts given to the bot on irc channels and display the results.

It requires LXD to be setup


No idea if ive done this the best way but here's the commands I've run to get set up. My system was running ubuntu 20
lxd requires snap to be setup so have that installed
You can skip much of this if you already have lxc/lxd setup just need the commands to make the container

```
apt install lxc lxd debian-archive-keyring
snap install lxd
lxd init
lxc launch images:debian/10 codesand
lxc exec codesand -- /bin/bash
```
Inside the container:
```
apt install php-cli build-essential python
adduser codesand
exit
```
install anything else you might need ro running scripts like dependencies?
```
lxc config set codesand boot.autostart=true
lxc profile copy default codesand
lxc profile edit codesand
```
Make it look like this adjust values to your liking
```
config:
  limits.cpu.allowance: 80%
  limits.memory: 500MiB
  limits.processes: "200"
description: Default LXD profile
devices:
  root:
    path: /
    pool: default
    type: disk
name: codesand
used_by: []
```
Notice I removed the network device

```
lxc profile assign codesand codesand
lxc snapshot codesand default
# add the user running bots
usermod -a -G lxd USERNAME
```

Using lxd the containers are already ran unprivileged.

One limitation is we're only going to be able to run one code snippet at a time.

Basically the script will do as follows:
* copy code to a file on instance
  ```
  lxc file push test.php codesand/home/codesand/
  lxc exec codesand -- /bin/chown codesand:codesand /home/codesand/test.php
  ```
* execute that file
  ```
  timeout 15 lxc exec codesand -- su --login codesand -c 'php test.php'
  ```
* reset instance to default snapshot
  ```
  lxc restore codesand default
  ```
  seems to take about 10 seconds?
  * maybe just rm -rf /home/codesand/* and do resets daily or something,
  * Also monitor disk usage before and after and determine if last exec used a lot of disk.
* send output to irc (with restrictions)


###Things to consider:
* Timeout on execution
  * Instance root will killall -u codesand after time is up
  * timeout command wont work with forkbombs, we will need to 
  * Want to be able to show in channel reply that it timed out.
* How OOM and forkbomb etc gets handled
  * Forkbombs I think will be handled ok with timeout and default ulimits
  * OOM not sure but I would hope the app is victim of failed malloc and the kernel memory killer doesnt get stupid 
