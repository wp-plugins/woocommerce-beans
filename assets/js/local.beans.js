beans_bind_event(window, "beans.has_card", receiveBeansEvent);
beans_bind_event(window, "beans.use_reward", receiveBeansEvent);

function receiveBeansEvent(e){
    var f = document.createElement("form");
    f.setAttribute("method", "post");
    f.setAttribute("action", "");
    var h = document.createElement("input");
    h.setAttribute("type", "hidden");
    h.setAttribute("name", "_beans_updt_cart_");
    h.setAttribute("value", "1");
    f.appendChild(h);
    document.body.appendChild(f);
    f.submit();
}

function showBeansMsg(beans_msg, beans_card_name){
    if(beans_u_gc('_beans_mm') || !beans_msg) return;
    beans_show_message('$$'+beans_card_name, beans_msg);
    beans_u_sc('_beans_mm',1,7);
}