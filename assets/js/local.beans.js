

(function(d, s, id){
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {return;}
    js = d.createElement(s); js.id = id;
    js.src = "//www.trybeans.com/assets/static/js/oauth.beans.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'beans-connect-js'));


window.beansAsyncInit = function() {

    Beans.onSuccess = function(){
        if(!beans_data.account){
            window.location = window.location.href;
        }
        return true;
    };

    Beans.onLogin = function(){
        window.location = beans_data.login_url;
        return true;
    };

    Beans.init({
        id : beans_data.card_id,
        default_connect_mode: beans_data.connect_num
    });

    if(beans_data.authentication){
        (function(el,n,h){
            if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
        )(document, 'DOMContentLoaded', beans_authentify);
    }
    (function(el,n,h){
        if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
    )(document, 'DOMContentLoaded', beans_show_msg);
};

function beans_authentify(){
    var url = '//www.trybeans.com/oauth/authentify/?client_id='
        +beans_data.card_id+'&authentication='+beans_data.authentication
        +'&account='+beans_data.account;
    var frame_id = 'beans_authenticate_frame';
    var f = document.getElementById(frame_id);
    if (f) {f.src = url; Beans.connect(3);}
    f = document.createElement('iframe');
    f.id = frame_id;
    f.name = frame_id;
    f.src = url;
    f.setAttribute('allowtransparency', 'true');
    var s = 'border:none; overflow:hidden; position: fixed; top:-100px; left: -100px;width: 20px; height: 20px';
    f.setAttribute('style', s);
    document.body.appendChild(f);
    Beans.connect(3);
}

function beans_show_msg(){
    // Get cookie
	var gc = function(k){return(document.cookie.match('(^|; )'+k+'=([^;]*)')||0)[2];};
    // Set cookie
    var sc = function(n,v,d){document.cookie=n+"="+v+"; expires="+(new Date(Date.now()+86400000*d)).toGMTString();};  

	if(gc('_beans_mm') || !beans_data.beans_popup) return;
	if(Beans.show(1,beans_data.connect_num)) sc('_beans_mm',1,30);
    return true;
}