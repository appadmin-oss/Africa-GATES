from pathlib import Path,PurePosixPath
import subprocess,tarfile,zipfile,hashlib,json,io,re,sys
from datetime import datetime,timezone
root=Path(__file__).resolve().parents[2]
head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
assert not subprocess.check_output(['git','status','--porcelain'],cwd=root,text=True).strip(), 'Commit the reviewed source before exporting'
release_date=datetime.now(timezone.utc).date().isoformat()
out=Path(sys.argv[1]) if len(sys.argv)>1 else root.parent/'exports'
out.mkdir(parents=True,exist_ok=True)
dest=out/f'Africa-GATES-production-{head[:8]}-{release_date}.zip'
stamp=datetime.fromtimestamp(int(subprocess.check_output(['git','show','-s','--format=%ct',head],cwd=root)),timezone.utc).timetuple()[:6]
raw=subprocess.check_output(['git','archive','--format=tar',head],cwd=root)
exclude_roots={'.github','.claude','docs','tests','tools','motion','vendor','node_modules','.phpunit.cache'}
exclude_files={'.gitignore','.gitattributes','CLAUDE.md','HANDOFF.md','HANDOFF-CLAUDE-CODE.md','phpunit.xml','phpunit.xml.dist','composer.phar'}
def include(name):
 p=PurePosixPath(name);parts=p.parts;leaf=p.name.lower()
 if parts[0] in exclude_roots or name in exclude_files:return False
 if leaf.startswith('.env') and leaf!='.env.example':return False
 if re.match(r'^(secrets?|credentials?)(\.|$)',leaf) or leaf in {'id_rsa','id_ed25519'}:return False
 if leaf.endswith(('.pem','.key','.p12','.pfx','.sqlite','.sqlite3','.db','.log','.zip','.tar.gz')):return False
 if name.startswith(('public/assets/img/utility/','public/assets/brand-preview/')):return False
 if parts[0]=='var':return leaf in {'.htaccess','.gitkeep'}
 if name.startswith('public/uploads/'):return leaf=='.htaccess'
 return True
files={}
with tarfile.open(fileobj=io.BytesIO(raw),mode='r:') as source:
 for m in source:
  if m.isfile() and include(m.name):files[m.name]=source.extractfile(m).read()
  elif m.issym() and include(m.name):raise RuntimeError('Review symlink before packaging: '+m.name)
manifest=json.loads((root/'public/assets/dist/manifest.json').read_text())
for name in ['assets/dist/manifest.json',manifest['file']]:files['public/'+name]=(root/'public'/name).read_bytes()
manifest['mtime']=int(subprocess.check_output(['git','show','-s','--format=%ct',head],cwd=root))
files['public/assets/dist/manifest.json']=(json.dumps(manifest,indent=4)+'\n').encode()
notes=f'''Africa GATES production update — {release_date}\nSource commit: {head}\n\nThis archive contains committed application code, migrations, original public assets and the rebuilt CSS bundle.\nExcluded: Composer vendor/, real environment/secrets/credential files, Git metadata, development tools/tests/audit records, caches/logs/databases/user uploads, and rejected/unapproved utility artwork. .env.example is a configuration template, not a production secrets file.\n\nUpdate the existing project root, preserving the server's .env or secrets file, vendor directory and uploaded files. Keep the document root pointed at public/. No upload/delete synchronisation should remove server files omitted from this archive.\nIf dependencies are not already installed, run composer install --no-dev --optimize-autoloader using the included lockfile. Review and run php bin/console db:migrate against the configured production database using the normal deployment process. Ensure the web-server user can write the runtime var directories and public/uploads.\n\nIncludes the modular event editor, direct event cover uploads, searchable event directory, accessible stepped-form validation, and the latest branch event-team/map-link improvements. No extra event UX migration is required. Includes repaired nominee portrait selection and existing-upload backfill, authenticated member profile photos, correct WebP encoding, shared accessible upload controls, and effective host-limit feedback. Run php bin/console db:migrate to apply 2027_04_30_account_and_nominee_photos.php before verifying member photo uploads. The shared homepage image selector uses existing default covers where unapproved utility artwork is absent. The previous complete test suite retained known failures documented in the source repository; this archive is not a claim that every outstanding defect has been resolved.\n'''
files['DEPLOYMENT-NOTES.txt']=notes.encode()
files['PRODUCTION-MANIFEST.json']=json.dumps({'commit':head,'packaged_date':release_date,'files':{name:hashlib.sha256(data).hexdigest() for name,data in sorted(files.items())}},indent=2).encode()
with zipfile.ZipFile(dest,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for name,data in sorted(files.items()):
  info=zipfile.ZipInfo(name,date_time=stamp);info.external_attr=(0o100755 if name=='bin/console' else 0o100644)<<16
  z.writestr(info,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 for name in ['var/cache/','var/cache/twig/','var/data/','var/logs/','var/sessions/','var/uploads/']:
  info=zipfile.ZipInfo(name,date_time=stamp);info.external_attr=(0o40755<<16)|0x10;z.writestr(info,b'')
with zipfile.ZipFile(dest) as z:
 names=z.namelist();assert z.testzip() is None
 recorded=json.loads(z.read('PRODUCTION-MANIFEST.json'))
 assert all(hashlib.sha256(z.read(name)).hexdigest()==digest for name,digest in recorded['files'].items())
 assert not any(n.startswith(('vendor/','.git/','tests/','docs/','tools/','motion/')) for n in names)
 assert not any(PurePosixPath(n).name.startswith('.env') and PurePosixPath(n).name!='.env.example' for n in names)
 assert all(n in names for n in ['public/index.php','public/.htaccess','public/uploads/.htaccess','config/container.php','composer.lock','resources/fonts/PlayfairDisplay-Bold.ttf']) if 'resources/fonts/PlayfairDisplay-Bold.ttf' in files else all(n in names for n in ['public/index.php','public/.htaccess','public/uploads/.htaccess','config/container.php','composer.lock'])
 assert not any(n.startswith('public/assets/img/utility/') for n in names)
 checksum=hashlib.sha256(dest.read_bytes()).hexdigest();dest.with_suffix('.zip.sha256').write_text(checksum+'  '+dest.name+'\n')
 print(json.dumps({'archive':str(dest),'commit':head,'files':len(files),'bytes':dest.stat().st_size,'sha256':checksum,'crc':'passed','vendor':'excluded','secrets':'excluded','unapproved_artwork':'excluded'},indent=2))
