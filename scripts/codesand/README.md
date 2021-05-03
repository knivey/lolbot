##This script is to run code snipperts given to the bot on irc channels and display the results.

It requires LXD to be setup


No idea if ive done this the best way but here's the commands I've run to get set up. My system was running Debian GNU/Linux 10 (buster)
lxd requires snap to be setup.

You can skip much of this if you already have lxc/lxd setup just need the commands to make the container

###Do the following commands as root
```bash
apt install lxc debian-archive-keyring snapd
snap install core
snap install lxd
# this may be different for non debian, supposedly you could relogin too
export PATH=$PATH:/snap/bin
# I just used all defaults for init
lxd init
```
Now you can create the container and enter it:
```bash
lxc launch images:debian/10 codesand
lxc exec codesand -- /bin/bash
```

###Inside the container:

```
apt install php-cli build-essential python
adduser codesand
```
install anything else you might need for running scripts etc
```bash
exit
```

###Adjust container settings profile
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
description: Default codesand LXD profile
devices:
  root:
    path: /
    pool: default
    type: disk
name: codesand
used_by: []
```
*Notice I removed the network device*

```bash
lxc profile assign codesand codesand
lxc snapshot codesand default
```

### Add the user running bots to lxd group
```bash
usermod -a -G lxd USERNAME
```

### Setup "pool" of containers
I recommend making at least a few container copies and the script will rotate them. It takes several seconds for a container to reset and restart.

```bash
lxc copy codesand codesand1
lxc copy codesand codesand2
lxc copy codesand codesand3
lxc copy codesand codesand4
lxc copy codesand codesand5

lxc start codesand1
lxc start codesand2
lxc start codesand3
lxc start codesand4
lxc start codesand5
```

Put inside the container.list in codesand script dir so we knows the names of containers to use
```txt
codesand1
codesand2
codesand3
codesand4
codesand5
```

### Other thoughts
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
* send output to irc (with restrictions)


###Things to consider:
* Timeout on execution
  * Instance root will killall -u codesand after time is up
  * timeout command wont work with forkbombs, we will need to 
  * Want to be able to show in channel reply that it timed out.
* How OOM and forkbomb etc gets handled
  * Forkbombs I think will be handled ok with timeout and default ulimits
  * OOM hope and pray
