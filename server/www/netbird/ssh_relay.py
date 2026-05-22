#!/usr/bin/env python3
"""
ssh_relay.py — MARS APRS NetBird

Paramiko PTY relay. Reads SSH channel output, base64-encodes each chunk to
stdout for ssh_stream.php to forward as SSE events.

Keyboard input is read from /tmp/aprs_ssh_<token>.q via atomic rename.
Resize events are read from /tmp/aprs_ssh_<token>.resize.

Args: <token> <ip> <user> <pass>

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2025 Doug Kaye, K6DRK <doug@rds.com>
"""

import sys
import os
import time
import base64
import paramiko


def main():
    if len(sys.argv) < 5:
        sys.stderr.write('Usage: ssh_relay.py <token> <ip> <user> <pass>\n')
        sys.exit(1)

    token, ip, user, password = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
    q_file = f'/tmp/aprs_ssh_{token}.q'
    r_file = f'/tmp/aprs_ssh_{token}.resize'

    def emit(data: bytes):
        sys.stdout.write(base64.b64encode(data).decode() + '\n')
        sys.stdout.flush()

    def cleanup():
        for f in [q_file, r_file]:
            try:
                os.unlink(f)
            except OSError:
                pass

    try:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(ip, username=user, password=password,
                       timeout=10, look_for_keys=False, allow_agent=False)
    except paramiko.AuthenticationException:
        sys.stdout.write('__AUTH_FAILED__\n')
        sys.stdout.flush()
        cleanup()
        sys.exit(0)
    except Exception as exc:
        emit(f'\r\nSSH connection failed: {exc}\r\n'.encode())
        cleanup()
        sys.exit(0)

    chan = client.invoke_shell(term='xterm-256color', width=220, height=50)
    chan.settimeout(0)

    try:
        while True:
            # SSH output → base64 → stdout
            try:
                data = chan.recv(4096)
                if data:
                    emit(data)
                elif chan.closed or chan.exit_status_ready():
                    break
            except Exception:
                pass

            # Keyboard input: atomic rename prevents partial reads
            if os.path.exists(q_file):
                try:
                    tmp = q_file + '.rd'
                    os.rename(q_file, tmp)
                    with open(tmp, 'rb') as f:
                        inp = f.read()
                    os.unlink(tmp)
                    if inp:
                        chan.sendall(inp)
                except Exception:
                    pass

            # Resize event
            if os.path.exists(r_file):
                try:
                    with open(r_file) as f:
                        cols, rows = map(int, f.read().strip().split(','))
                    os.unlink(r_file)
                    chan.resize_pty(width=cols, height=rows)
                except Exception:
                    pass

            time.sleep(0.02)

    finally:
        try:
            chan.close()
        except Exception:
            pass
        try:
            client.close()
        except Exception:
            pass
        cleanup()


if __name__ == '__main__':
    main()
