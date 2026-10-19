import socketserver,socket,select,subprocess,json,pathlib,sys
s=json.loads(pathlib.Path(sys.argv[1]).read_text())
web=s['db'][:-3]+'-web'
a=json.loads(subprocess.check_output(['docker','inspect',web]))[0]['NetworkSettings']['Networks'][s['network']]['IPAddress']
class Relay(socketserver.BaseRequestHandler):
 def handle(self):
  with socket.create_connection((a,80)) as upstream:
   sockets=[self.request,upstream]
   while True:
    ready,_,_=select.select(sockets,[],[],30)
    if not ready:return
    for src in ready:
     data=src.recv(65536)
     if not data:return
     (upstream if src is self.request else self.request).sendall(data)
class Server(socketserver.ThreadingTCPServer):
 allow_reuse_address=True
 daemon_threads=True
with Server(('127.0.0.1',18763),Relay) as server:server.serve_forever()
