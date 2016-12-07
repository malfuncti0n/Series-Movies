<?php

namespace App\Controllers;

use App\Models\Actor;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Image;
use App\Models\Director;
use Slim\Views\Twig as View;
use Respect\Validation\Validator as v;
use Cocur\Slugify\Slugify as slug;
use Carbon\Carbon as carbon;
use App\Services\DataApi;


class SeriesController extends Controller
{
    protected $datadir='files';
    
    
    public function getCreateSeries($request,$response)
    {
        return $this->container->view->render($response, 'series.create.twig');
    }

    public function postCreateSeries($request,$response){
        
       //get files
       $image=$request->getUploadedFiles();

       //if image has error do not proceed
       if($image['input-file-preview']->getError()){
           $this->flash->addMessage('error', 'your image faled to upload');
            return $response->withRedirect($this->router->pathFor('series.create'));
       }
       
        //validate input
        $validation = $this->validator->validate($request, [
            // ->emailAvailable()
            'name' => v::notEmpty(),
            'description' => v::notEmpty(),
            'director' => v::notEmpty(),
            'actor' => v::notEmpty(),
            'release_day'=>v::date(),
            'trailer' =>v::videoUrl()
        ]);
        if ($validation->failed()) {
            $this->flash->addMessage('error', 'Your series was unable to create');
            return $response->withRedirect($this->router->pathFor('series.create'));
        }
        //use slug class to sluglify series name
        $slug=new Slug;
        $date = Carbon::parse($request->getParam('release_day'));
        $sluglified=$slug->slugify($request->getParam('name'));

        //insert series to database
        $series=Series::create([
            'name' => $request->getParam('name'),
            'description' => $request->getParam('description'),
            'release_day' => $date,
            'slug' => $sluglified,
            'trailer_url' =>$request->getParam('trailer')
        ]);

        //create director
        $createdDirector=Director::create([
           'full_name'=> $request->getParam('director'),
            'slug' => $slug->slugify($request->getParam('director'))
        ]);
        $series->directors()->attach($createdDirector);

            
        //explode tags in array split by comma
        $tags=explode(',',$request->getParam('tag'));
        foreach($tags as $tag){
            //create new tag in database
            $createdTag[$tag]=Tag::create([
                'value' => $tag,
                'slug' => $slug->slugify($tag)
            ]);
            //define tag with many to many relationship
            $series->tags()->attach($createdTag[$tag]);

        }

        //explode actors
        $actors=explode(',',$request->getParam('actor'));
        foreach($actors as $actor){
            //create new actor in database
            $createdActor[$actor]=Actor::create([
                'full_name' => $actor,
                'slug' => $slug->slugify($actor)
            ]);
            //define tag with many to many relationship
            $series->actors()->attach($createdActor[$actor]);

        }
        
       //calculate hash and get image data
       $dataApi=new DataApi;
       $imageFilename=$image['input-file-preview']->getClientFilename();
       $imageSize=$image['input-file-preview']->getSize();
       $imageHash=$dataApi->calculateHash($image['input-file-preview']->file);
        $contentType=$image['input-file-preview']->getClientMediaType();
       
       //check if file exist in datadir
       if(!$dataApi->hashExistInData($imageHash)){
          //if not exist den we move it in upload dir 
           move_uploaded_file($image['input-file-preview']->file,__DIR__ . '/../../resources/'.$this->datadir.'/'.$imageHash) ;
          //and we make a new entry in database
           $image=new Image;
           $image->filename=$imageFilename;
           $image->hash=$imageHash;
           $image->slug=$slug->slugify($image->filename);
           $image->size=$imageSize;
           $image->content_type=$contentType;
           $image->uploaded_dir=__DIR__ . '/../../resources/'.$this->datadir.'/'.$imageHash;
       }else{
           //if file exist with the same hash then our file exist from onother upload
           //find it in database and make new entry
           $imageExist=Image::where('hash','=',$imageHash)->first();
           $image=new Image;
           $image->filename=$imageExist->filename;
           $image->hash=$imageExist->hash;
           $image->slug=$imageExist->slug;
           $image->size=$imageExist->size;
           $image->content_type=$contentType;
           $image->uploaded_dir=$imageExist->uploaded_dir;
       }
       //save image in database
       $series->images()->save($image);
   
        $this->flash->addMessage('success', 'Your Series created!');
        return $response->withRedirect($this->router->pathFor('series'));

    }

        public function getSeries($request, $response){
            $allSeries = Series::all();
            
            $this->view->getEnvironment()->addGlobal('allSeries',$allSeries);
            return $this->view->render($response, 'series.twig');
        }

        public function getSeriesPost($request, $response){
            $series=Series::where('slug','=',$request->getAttribute('routeInfo')[2]['slug'])->first();
            if($series){
                $this->view->getEnvironment()->addGlobal('series',$series);
                $this->view->render($response, 'series.post.twig');
            }else{
                $this->view->render($response, '404.twig');
                return $response->withStatus(404);
            }
        }
}