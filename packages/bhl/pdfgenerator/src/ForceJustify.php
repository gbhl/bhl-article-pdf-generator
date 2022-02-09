<?php

class CustomPdf extends \tFPDF {
	function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='') {
    $k=$this->k;
    if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
			$x=$this->x;
			$ws=$this->ws;
			if($ws>0) {
				$this->ws=0;
				$this->_out('0 Tw');
			}
			$this->AddPage($this->CurOrientation);
			$this->x=$x;
			if($ws>0) {
				$this->ws=$ws;
				$this->_out(sprintf('%.3F Tw',$ws*$k));
			}
    }
    if($w==0)
			$w=$this->w-$this->rMargin-$this->x;
    $s='';
    if($fill || $border==1) {
			if($fill)
				$op=($border==1) ? 'B' : 'f';
			else
				$op='S';
			$s=sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
    }
    if(is_string($border)) {
			$x=$this->x;
			$y=$this->y;
			if(is_int(strpos($border,'L')))
				$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
			if(is_int(strpos($border,'T')))
				$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
			if(is_int(strpos($border,'R')))
				$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
			if(is_int(strpos($border,'B')))
				$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
    }
    if($txt!='') {
			if($align=='R') {
				$dx=$w-$this->cMargin-$this->GetStringWidth($txt);
			} elseif($align=='C') {
				$dx=($w-$this->GetStringWidth($txt))/2;
			} elseif($align=='FJ') {
				//Set word spacing
				$wmax=($w-2*$this->cMargin);
				if (substr_count($txt,' ') > 0){
					$this->ws = ($wmax-$this->GetStringWidth($txt)) / substr_count($txt,' ');
				} else {
					$this->ws = ($wmax-$this->GetStringWidth($txt));
				}
				
				$this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
				$dx=$this->cMargin;
			} else {
				$dx=$this->cMargin;
			}		
			$txt=str_replace(')','\\)',str_replace('(','\\(',str_replace('\\','\\\\',$txt)));
			if($this->ColorFlag) {
				$s.='q '.$this->TextColor.' ';
			}
			// Make this handle multibyte things properly. Copied from tfpdf.php, line 737
			if ($this->ws && $this->unifontSubset) {
				foreach($this->UTF8StringToArray($txt) as $uni)
					$this->CurrentFont['subset'][$uni] = $uni;
				$space = $this->_escape($this->UTF8ToUTF16BE(' ', false));
				$s .= sprintf('BT 0 Tw %.2F %.2F Td [',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k);
				$t = explode(' ',$txt);
				$numt = count($t);
				for($i=0;$i<$numt;$i++) {
					$tx = $t[$i];
					$tx = '('.$this->_escape($this->UTF8ToUTF16BE($tx, false)).')';
					$s .= sprintf('%s ',$tx);
					if (($i+1)<$numt) {
						$adj = -($this->ws*$this->k)*1000/$this->FontSizePt;
						$s .= sprintf('%d(%s) ',$adj,$space);
					}
				}
				$s .= '] TJ';
				$s .= ' ET';
			}
			else {
				if ($this->unifontSubset)
				{
					$txt2 = '('.$this->_escape($this->UTF8ToUTF16BE($txt, false)).')';
					foreach($this->UTF8StringToArray($txt) as $uni)
						$this->CurrentFont['subset'][$uni] = $uni;
				}
				else
					$txt2='('.$this->_escape($txt).')';
				$s .= sprintf('BT %.2F %.2F Td %s Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$txt2);
			}

			if($this->underline) {
				$s.=' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
			}	
			if($this->ColorFlag) {
				$s.=' Q';
			}
			if($link) {
				if($align=='FJ')
					$wlink=$wmax;
				else
					$wlink=$this->GetStringWidth($txt);
				$this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$wlink,$this->FontSize,$link);
			}
    }
    if($s) {
			$this->_out($s);
		}
    if($align=='FJ') {
			//Remove word spacing
			$this->_out('0 Tw');
			$this->ws=0;
    }
    $this->lasth=$h;
    if($ln>0) {
			$this->y+=$h;
			if($ln==1) {
				$this->x=$this->lMargin;
			}
    } else {
			$this->x+=$w;
		}
	}
}