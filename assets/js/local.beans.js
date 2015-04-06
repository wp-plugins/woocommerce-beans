(function(d, s, id){
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {return;}
    js = d.createElement(s); js.id = id;
    js.src = "//www.trybeans.com/assets/static/js/oauth.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'beans-connect-js'));

window.beansAsyncInit = function() {

    Beans.onSuccess = function(){
        window.location = window.location.href;
        return true;
    };

    Beans.init({
         id : beans_data.card_id
    });

    (function(el,n,h){
        if(el.addEventListener){el.addEventListener(n,h,false);}else if(el.attachEvent){el.attachEvent('on'+n,h);}}
    )(document, 'DOMContentLoaded', beans_show_msg);
};

function beans_show_msg(){
    // Get cookie
	var gc = function(k){return(document.cookie.match('(^|; )'+k+'=([^;]*)')||0)[2];};
    // Set cookie
    var sc = function(n,v,d){document.cookie=n+"="+v+"; expires="+(new Date(Date.now()+86400000*d)).toGMTString();};  

	if(gc('_beans_mm') || !beans_data.beans_popup ) return;
	if(Beans.show()) sc('_beans_mm',1,30);
    return true;
}