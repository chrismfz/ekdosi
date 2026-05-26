//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "RegistryAccess.h"
#include "FShowBalance.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmShowBalance *FrmShowBalance;
//---------------------------------------------------------------------------
__fastcall TFrmShowBalance::TFrmShowBalance(TComponent* Owner)
	: NewSpecialForm(Owner)
{

 RegAccess *reg = new RegAccess(this);
 reg->getColors(&primary, &secondary, &selectedColor);

// GridCustomers->Color = primary;

 DatasetCustomer->Open();
}
//---------------------------------------------------------------------------
