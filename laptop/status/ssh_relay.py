#!/usr/bin/env python3
"""
SSH relay for MARS APRS Status.
Usage: ssh_relay.py <ip> <user> <password> <queue_file> [cols] [rows]

Protocol (stdout, one line each):
  CONNECTED          – shell opened successfully
  ERROR:<msg>        – connection/auth failed; script exits
  DATA:<base64>      – SSH output chunk

Resize: write "<cols>:<rows>" to <queue_file>.resize — picked up next tick.
"""
import sys, os, time, base64

def drain_queue(path):
    try:
        import fcntl
        with open(path, 'r+b') as f:
            fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
            data = f.read()
            if data:
                f.seek(0)
                f.truncate(0)
            fcntl.flock(f, fcntl.LOCK_UN)
        return data
    except (FileNotFoundError, BlockingIOError):
        return b''
    except Exception:
        return b''

def check_resize(resize_file):
    try:
        txt = open(resize_file).read().strip()
        os.unlink(resize_file)
        c, r = map(int, txt.split(':'))
        return c, r
    except Exception:
        return None

def emit(line):
    sys.stdout.write(line + '\n')
    sys.stdout.flush()

def main():
    if len(sys.argv) < 5:
        emit('ERROR:Usage: ssh_relay.py ip user pass queue_file [cols] [rows]')
        return

    ip, user, password, queue_file = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
    cols = int(sys.argv[5]) if len(sys.argv) > 5 else 80
    rows = int(sys.argv[6]) if len(sys.argv) > 6 else 24
    resize_file = queue_file + '.resize'

    try:
        import paramiko
    except ImportError:
        emit('ERROR:paramiko not installed')
        return

    try:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(ip, username=user, password=password, timeout=10,
                       look_for_keys=False, allow_agent=False)
        chan = client.invoke_shell(term='xterm-256color', width=cols, height=rows)
    except paramiko.AuthenticationException:
        emit('ERROR:Authentication failed.')
        return
    except Exception as e:
        emit(f'ERROR:{e}')
        return

    emit('CONNECTED')
    chan.setblocking(False)

    try:
        while True:
            try:
                data = chan.recv(8192)
                if data:
                    emit('DATA:' + base64.b64encode(data).decode())
                else:
                    break  # b'' = EOF; channel closed
            except Exception:
                if chan.closed or chan.exit_status_ready():
                    break
                # otherwise just no data yet (non-blocking timeout), continue

            inp = drain_queue(queue_file)
            if inp:
                chan.sendall(inp)

            resize = check_resize(resize_file)
            if resize:
                chan.resize_pty(*resize)

            time.sleep(0.02)
    finally:
        chan.close()
        client.close()
        try: os.unlink(resize_file)
        except: pass

if __name__ == '__main__':
    main()
