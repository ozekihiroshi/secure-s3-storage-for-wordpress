<?php
/** Read-only fixture fetcher. Writes always use the product client directly. */
final class WordPressDirectS3Reader
{
    public array $objects=[];
    public function __construct(private \SecureS3StorageForWordpress\Aws\MediaS3Client $client) {}
    public function path(string $bucket,string $key):string
    {
        $prefix=getenv('FIXTURE_S3_PREFIX');
        if ($bucket!=='ceri-secure-s3-storage-test' || !preg_match('#^wordpress-test/direct-worker/[a-f0-9]{12}/(native|php)$#D',$prefix)
            || !str_starts_with($key,$prefix.'/')) { throw new RuntimeException('Dedicated S3 scope required.'); }
        $path='/remote/'.hash('sha256',$bucket.'/'.$key);
        if (is_file($path)) { return $path; }
        $head=$this->client->request('HeadObject',['Bucket'=>$bucket,'Key'=>$key,'ChecksumMode'=>'ENABLED'],time()+60);
        $size=$head['ContentLength']??-1;
        if ($size<0 || $size>16777216) { throw new RuntimeException('Bounded download required.'); }
        $args=['Bucket'=>$bucket,'Key'=>$key];
        if (isset($head['VersionId'])) { $args['VersionId']=$head['VersionId']; }
        else { $args['IfMatch']=$head['ETag']; }
        $r=$this->client->request('GetObject',$args,time()+60);
        $body=$r['Body'];$out=fopen($path,'xb');$count=0;$hash=hash_init('sha256');
        try {
            while (!$body->eof()) {
                $chunk=$body->read(65536);$count+=strlen($chunk);
                if ($count>$size || ($chunk===''&&!$body->eof()) || fwrite($out,$chunk)!==strlen($chunk)) { throw new RuntimeException('Download mismatch.'); }
                hash_update($hash,$chunk);
            }
        } finally { $body->close();fclose($out); }
        $digest=hash_final($hash);
        if ($count!==$size || (isset($head['ChecksumSHA256']) && base64_encode(hex2bin($digest))!==$head['ChecksumSHA256'])) { throw new RuntimeException('Download integrity mismatch.'); }
        $this->objects[]=['key'=>$key,'size_bytes'=>$size,'sha256'=>$digest,'version_id'=>$head['VersionId']??null,'encryption'=>$head['ServerSideEncryption']??null];
        return $path;
    }
}
